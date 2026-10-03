<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function autocontrollo_temperature_schedule(array $range): array {
  $timezone = new DateTimeZone('Europe/Rome');
  $start = !empty($range['start']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $timezone) : false;
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone) : false;
  if (!$start || !$end || $start > $end) return [];
  $schedule = [];
  for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
    foreach (['mattina', 'pomeriggio'] as $slot) $schedule[] = ['date' => $date->format('Y-m-d'), 'slot' => $slot];
  }
  return $schedule;
}

function autocontrollo_temperature_next_due(array $range, array $completedInspections): ?array {
  $completed = [];
  foreach ($completedInspections as $inspection) {
    if (($inspection['status'] ?? '') === 'completata') $completed[(string)$inspection['inspection_date'] . '|' . (string)$inspection['time_slot']] = true;
  }
  foreach (autocontrollo_temperature_schedule($range) as $control) {
    if (!isset($completed[$control['date'] . '|' . $control['slot']])) return $control;
  }
  return null;
}

function autocontrollo_temperature_is_available(string $date, string $slot, ?DateTimeImmutable $now = null): bool {
  $timezone = new DateTimeZone('Europe/Rome');
  $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
  $today = $now->format('Y-m-d');
  if ($date > $today) return false;
  if ($date < $today || $slot !== 'pomeriggio') return true;
  return $now->format('H:i') >= '12:00';
}

/**
 * Crea o sovrascrive con esito conforme tutti i controlli dalla data di
 * apertura fino a oggi (o alla chiusura, se antecedente). Pensata per il
 * riallineamento una tantum dei dati storici.
 */
function autocontrollo_temperature_backfill_compliant(PDO $pdo, array $range, ?DateTimeImmutable $today = null): array {
  $timezone = new DateTimeZone('Europe/Rome');
  $today = ($today ?? new DateTimeImmutable('today', $timezone))->setTimezone($timezone);
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone) : false;
  if (empty($range['start']) || !$end) throw new RuntimeException('Configurare le date di apertura e chiusura della stagione.');
  $effectiveEnd = $end < $today ? $end : $today;
  $controls = array_values(array_filter(autocontrollo_temperature_schedule($range), static fn(array $control): bool => $control['date'] <= $effectiveEnd->format('Y-m-d')));
  if (!$controls) return ['inspections' => 0, 'results' => 0];
  $refrigerators = $pdo->query('SELECT id, appliance_type, operating_temperature FROM autocontrollo_refrigerators ORDER BY appliance_type, id')->fetchAll(PDO::FETCH_ASSOC);
  if (!$refrigerators) throw new RuntimeException('Configurare almeno un frigorifero nei Setting Autocontrollo.');

  $pdo->beginTransaction();
  try {
    $find = $pdo->prepare('SELECT id FROM autocontrollo_temperature_inspections WHERE season_start=? AND season_end=? AND inspection_date=? AND time_slot=? LIMIT 1');
    $create = $pdo->prepare("INSERT INTO autocontrollo_temperature_inspections (season_start, season_end, inspection_date, time_slot, status, started_at, completed_at) VALUES (?, ?, ?, ?, 'completata', ?, ?)");
    $complete = $pdo->prepare("UPDATE autocontrollo_temperature_inspections SET status='completata', completed_at=? WHERE id=?");
    $deleteResults = $pdo->prepare('DELETE FROM autocontrollo_temperature_results WHERE inspection_id=?');
    $insertResult = $pdo->prepare('INSERT INTO autocontrollo_temperature_results (inspection_id, refrigerator_id, refrigerator_label, appliance_type, operating_temperature, sort_order, is_compliant, checked_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    $resultCount = 0;
    foreach ($controls as $control) {
      $time = $control['slot'] === 'mattina' ? '09:00:00' : '17:00:00';
      $checkedAt = $control['date'] . ' ' . $time;
      $find->execute([$range['start'], $range['end'], $control['date'], $control['slot']]);
      $inspectionId = (int)($find->fetchColumn() ?: 0);
      if ($inspectionId === 0) {
        $create->execute([$range['start'], $range['end'], $control['date'], $control['slot'], $checkedAt, $checkedAt]);
        $inspectionId = (int)$pdo->lastInsertId();
      } else {
        $complete->execute([$checkedAt, $inspectionId]);
        $deleteResults->execute([$inspectionId]);
      }
      foreach ($refrigerators as $index => $refrigerator) {
        $insertResult->execute([$inspectionId, $refrigerator['id'], $refrigerator['id'], $refrigerator['appliance_type'], $refrigerator['operating_temperature'], $index + 1, $checkedAt]);
        $resultCount++;
      }
    }
    $pdo->commit();
    return ['inspections' => count($controls), 'results' => $resultCount];
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function autocontrollo_temperature_start(PDO $pdo, array $range, string $date, string $slot, int $operatorId): int {
  if (!in_array($slot, ['mattina', 'pomeriggio'], true)) throw new InvalidArgumentException('Fascia oraria non valida.');
  if (empty($range['start']) || empty($range['end']) || $date < $range['start'] || $date > $range['end']) throw new RuntimeException('La data non rientra nella stagione configurata.');
  if (!autocontrollo_temperature_is_available($date, $slot)) {
    throw new RuntimeException($slot === 'pomeriggio' ? 'Il controllo del pomeriggio è disponibile dalle ore 12:00.' : 'Il controllo non è ancora disponibile.');
  }
  $openStmt = $pdo->prepare("SELECT id FROM autocontrollo_temperature_inspections WHERE season_start=? AND season_end=? AND status='in_corso' LIMIT 1");
  $openStmt->execute([$range['start'], $range['end']]);
  if ($openStmt->fetchColumn() !== false) throw new RuntimeException('Completare la procedura già in corso prima di avviarne una nuova.');
  $completedStmt = $pdo->prepare('SELECT inspection_date, time_slot, status FROM autocontrollo_temperature_inspections WHERE season_start=? AND season_end=?');
  $completedStmt->execute([$range['start'], $range['end']]);
  $nextDue = autocontrollo_temperature_next_due($range, $completedStmt->fetchAll(PDO::FETCH_ASSOC));
  if (!$nextDue || $nextDue['date'] !== $date || $nextDue['slot'] !== $slot) throw new RuntimeException('Completare prima tutti i controlli antecedenti.');
  $refrigerators = $pdo->query('SELECT id, appliance_type, operating_temperature FROM autocontrollo_refrigerators ORDER BY appliance_type, id')->fetchAll(PDO::FETCH_ASSOC);
  if (!$refrigerators) throw new RuntimeException('Configurare almeno un frigorifero nei Setting Autocontrollo.');
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_temperature_inspections (season_start, season_end, inspection_date, time_slot, operator_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $date, $slot, $operatorId]);
    $id = (int)$pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO autocontrollo_temperature_results (inspection_id, refrigerator_id, refrigerator_label, appliance_type, operating_temperature, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($refrigerators as $index => $item) $insert->execute([$id, $item['id'], $item['id'], $item['appliance_type'], $item['operating_temperature'], $index + 1]);
    $pdo->commit();
    return $id;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ((string)$exception->getCode() === '23000') throw new RuntimeException('Il controllo selezionato è già stato avviato.');
    throw $exception;
  }
}

function autocontrollo_temperature_send_report(PDO $pdo, int $inspectionId): int {
  $stmt = $pdo->prepare("SELECT i.*, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, u.email operator_email FROM autocontrollo_temperature_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE i.id=?");
  $stmt->execute([$inspectionId]); $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$inspection) return 0;
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$inspectionId]);
  $rows = ''; $anomalies = 0;
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $result) {
    $ok = (int)$result['is_compliant'] === 1; if (!$ok) $anomalies++;
    $style = $ok ? '' : ' style="background:#f8d7da;color:#842029;font-weight:bold"';
    $rows .= '<tr' . $style . '><td>' . htmlspecialchars($result['refrigerator_label'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(ucfirst($result['appliance_type']), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)$result['operating_temperature'], ENT_QUOTES, 'UTF-8') . ' °C</td><td>' . ($ok ? 'Sì' : 'NO — ANOMALIA') . '</td></tr>';
  }
  $operator = trim((string)$inspection['operator_name']) ?: (string)$inspection['operator_email'];
  $html = '<h2>Autocontrollo Temperature</h2><p><strong>Data:</strong> ' . date('d/m/Y', strtotime($inspection['inspection_date'])) . '<br><strong>Fascia:</strong> ' . ucfirst($inspection['time_slot']) . '<br><strong>Eseguito:</strong> ' . date('d/m/Y H:i', strtotime($inspection['completed_at'] ?: $inspection['started_at'])) . '<br><strong>Operatore:</strong> ' . htmlspecialchars($operator, ENT_QUOTES, 'UTF-8') . '<br><strong>Anomalie:</strong> ' . $anomalies . '</p><table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>ID Frigo</th><th>Tipologia</th><th>Temperatura esercizio</th><th>Conforme</th></tr></thead><tbody>' . $rows . '</tbody></table>';
  $users = $pdo->query("SELECT email, dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll(PDO::FETCH_ASSOC); $sent = 0;
  foreach ($users as $recipient) if (user_has_department($recipient, 'Amministrazione') && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) && send_mail($recipient['email'], 'Esito autocontrollo temperature' . ($anomalies ? ' — ANOMALIE' : ''), $html)) $sent++;
  if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_temperature_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$inspectionId]);
  return $sent;
}
