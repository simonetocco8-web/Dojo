<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function autocontrollo_temperature_season_for_date(PDO $pdo, DateTimeInterface $date): array {
  $range = get_summer_season_range($pdo);
  if (empty($range['start']) || empty($range['end'])) throw new RuntimeException('Configurare le date di apertura e chiusura della stagione.');
  $value = $date->format('Y-m-d');
  if ($value < $range['start'] || $value > $range['end']) throw new RuntimeException('Oggi non rientra nella stagione configurata.');
  return $range;
}

function autocontrollo_temperature_start(PDO $pdo, string $period, int $userId): int {
  if (!in_array($period, ['mattina', 'pomeriggio'], true)) throw new InvalidArgumentException('Fascia di controllo non valida.');
  $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
  $range = autocontrollo_temperature_season_for_date($pdo, $today);
  $refrigerators = $pdo->query('SELECT id, refrigerator_identifier, location FROM autocontrollo_refrigerators ORDER BY refrigerator_identifier, id')->fetchAll();
  if (!$refrigerators) throw new RuntimeException('Configurare almeno un frigorifero nei setting prima di avviare la procedura.');

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_temperature_inspections (season_start, season_end, inspection_date, period, started_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $today->format('Y-m-d'), $period, $userId]);
    $inspectionId = (int)$pdo->lastInsertId();
    $result = $pdo->prepare('INSERT INTO autocontrollo_temperature_results (inspection_id, refrigerator_id, refrigerator_identifier, refrigerator_location, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($refrigerators as $index => $refrigerator) $result->execute([$inspectionId, $refrigerator['id'], $refrigerator['refrigerator_identifier'], $refrigerator['location'], $index + 1]);
    $pdo->commit();
    return $inspectionId;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof PDOException && $exception->getCode() === '23000') throw new RuntimeException('Il controllo di questa fascia oraria è già stato avviato oggi.');
    throw $exception;
  }
}

function autocontrollo_temperature_send_report(PDO $pdo, int $inspectionId): int {
  $stmt = $pdo->prepare('SELECT i.*, u.email operator_email FROM autocontrollo_temperature_inspections i LEFT JOIN users u ON u.id=i.started_by WHERE i.id=?');
  $stmt->execute([$inspectionId]);
  $inspection = $stmt->fetch();
  if (!$inspection) return 0;
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order');
  $stmt->execute([$inspectionId]);
  $rows = ''; $hasAnomalies = false;
  foreach ($stmt->fetchAll() as $result) {
    $ok = (int)$result['is_compliant'] === 1; $hasAnomalies = $hasAnomalies || !$ok;
    $rows .= '<tr><td>' . htmlspecialchars($result['refrigerator_identifier'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($result['refrigerator_location'], ENT_QUOTES, 'UTF-8') . '</td><td>' . ($ok ? '<strong style="color:#146c43">SÌ</strong>' : '<strong style="color:#b02a37">NO — ANOMALIA</strong>') . '</td></tr>';
  }
  $html = '<h2>Autocontrollo temperature</h2><p><strong>Data e ora:</strong> ' . htmlspecialchars($inspection['completed_at'] ?: $inspection['started_at'], ENT_QUOTES, 'UTF-8') . '</p><p><strong>Fascia:</strong> ' . ucfirst($inspection['period']) . '</p>'
    . ($hasAnomalies ? '<p style="color:#b02a37"><strong>ATTENZIONE: sono state rilevate temperature non conformi.</strong></p>' : '<p><strong>Tutte le temperature sono conformi.</strong></p>')
    . '<table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>ID frigo</th><th>Location</th><th>Conforme</th></tr></thead><tbody>' . $rows . '</tbody></table>';
  $users = $pdo->query("SELECT email, dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll();
  $sent = 0;
  foreach ($users as $recipient) if (user_has_department($recipient, 'Amministrazione') && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) && send_mail($recipient['email'], 'Esito autocontrollo temperature', $html)) $sent++;
  if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_temperature_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$inspectionId]);
  return $sent;
}
