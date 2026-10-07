<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/autocontrollo_schema.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/autocontrollo_rodent_schedule.php';

function autocontrollo_rodent_start(PDO $pdo, array $range, string $scheduledDate, int $operatorId): int {
  $schedule = autocontrollo_rodent_schedule($range);
  if (!in_array($scheduledDate, $schedule, true)) throw new InvalidArgumentException('Scadenza di derattizzazione non valida.');
  $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
  if (new DateTimeImmutable($scheduledDate, new DateTimeZone('Europe/Rome')) > $today) throw new RuntimeException('Il controllo non è ancora disponibile.');
  $openStmt = $pdo->prepare("SELECT id FROM autocontrollo_rodent_inspections WHERE season_start=? AND season_end=? AND status='in_corso' LIMIT 1");
  $openStmt->execute([$range['start'], $range['end']]);
  if ($openStmt->fetchColumn() !== false) throw new RuntimeException('Completare la procedura già in corso prima di avviarne una nuova.');
  $stmt = $pdo->prepare('SELECT scheduled_date FROM autocontrollo_rodent_inspections WHERE season_start=? AND season_end=? ORDER BY scheduled_date');
  $stmt->execute([$range['start'], $range['end']]);
  if (autocontrollo_rodent_next_date($schedule, $stmt->fetchAll(PDO::FETCH_COLUMN)) !== $scheduledDate) throw new RuntimeException('Completare prima la scadenza precedente.');
  $traps = $pdo->query('SELECT id, location FROM autocontrollo_rodent_traps ORDER BY location, id')->fetchAll(PDO::FETCH_ASSOC);
  if (!$traps) throw new RuntimeException('Configurare almeno una trappola nei Setting Autocontrollo.');

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_rodent_inspections (season_start, season_end, scheduled_date, operator_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $scheduledDate, $operatorId]);
    $inspectionId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_rodent_inspection_results (inspection_id, trap_id, trap_location, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($traps as $index => $trap) $stmt->execute([$inspectionId, $trap['id'], $trap['location'], $index + 1]);
    $pdo->commit();
    return $inspectionId;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function autocontrollo_rodent_send_report(PDO $pdo, int $inspectionId): int {
  $stmt = $pdo->prepare("SELECT i.*, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, u.email operator_email FROM autocontrollo_rodent_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE i.id=?");
  $stmt->execute([$inspectionId]);
  $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$inspection) return 0;
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_rodent_inspection_results WHERE inspection_id=? ORDER BY sort_order');
  $stmt->execute([$inspectionId]);
  $rows = '';
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $result) {
    $yesNo = static fn($value): string => (int)$value === 1 ? 'Sì' : 'No';
    $rows .= '<tr><td>' . htmlspecialchars($result['trap_location'], ENT_QUOTES, 'UTF-8') . '</td><td>' . $yesNo($result['bait_present']) . '</td><td>' . ($result['bait_eaten'] === null ? 'Non applicabile' : $yesNo($result['bait_eaten'])) . '</td><td>' . $yesNo($result['bait_replaced']) . '</td></tr>';
  }
  $operator = trim((string)$inspection['operator_name']) ?: (string)$inspection['operator_email'];
  $html = '<h2>Autocontrollo Derattizzazione</h2><p><strong>Scadenza:</strong> ' . date('d/m/Y', strtotime($inspection['scheduled_date'])) . '<br><strong>Eseguito:</strong> ' . date('d/m/Y H:i', strtotime($inspection['completed_at'] ?: $inspection['started_at'])) . '<br><strong>Operatore:</strong> ' . htmlspecialchars($operator, ENT_QUOTES, 'UTF-8') . '</p><table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>Trappola</th><th>Esca presente</th><th>Esca mangiata</th><th>Esca sostituita</th></tr></thead><tbody>' . $rows . '</tbody></table>';
  $users = $pdo->query("SELECT email, dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll(PDO::FETCH_ASSOC);
  $sent = 0;
  foreach ($users as $recipient) {
    if (user_has_department($recipient, 'Amministrazione') && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) && send_mail($recipient['email'], 'Esito autocontrollo derattizzazione', $html)) $sent++;
  }
  if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_rodent_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$inspectionId]);
  return $sent;
}
