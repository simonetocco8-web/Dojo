<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function autocontrollo_grounding_schedule(array $range): array {
  $timezone = new DateTimeZone('Europe/Rome');
  $start = !empty($range['start']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['start'], $timezone) : false;
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$range['end'], $timezone) : false;
  if (!$start || !$end || $start > $end) return [];
  return [
    'pre_apertura' => ['label' => 'Controllo pre-apertura', 'date' => $start->modify('-14 days')->format('Y-m-d')],
    'post_chiusura' => ['label' => 'Controllo post-chiusura', 'date' => $end->modify('+2 days')->format('Y-m-d')],
  ];
}

function autocontrollo_grounding_start(PDO $pdo, array $range, string $type, int $operatorId): int {
  $schedule = autocontrollo_grounding_schedule($range);
  if (!isset($schedule[$type])) throw new InvalidArgumentException('Momento di controllo non valido.');
  $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
  if (new DateTimeImmutable($schedule[$type]['date'], new DateTimeZone('Europe/Rome')) > $today) throw new RuntimeException('La procedura non è ancora disponibile.');
  $stmt = $pdo->prepare("SELECT id FROM autocontrollo_grounding_inspections WHERE season_start=? AND season_end=? AND status='in_corso' LIMIT 1");
  $stmt->execute([$range['start'], $range['end']]);
  if ($stmt->fetchColumn() !== false) throw new RuntimeException('Completare la procedura già in corso prima di avviarne una nuova.');
  $rods = $pdo->query('SELECT id, location FROM autocontrollo_grounding_rods ORDER BY location, id')->fetchAll(PDO::FETCH_ASSOC);
  if (!$rods) throw new RuntimeException('Configurare almeno una palina nei Setting Autocontrollo.');

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_grounding_inspections (season_start, season_end, inspection_type, scheduled_date, operator_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $type, $schedule[$type]['date'], $operatorId]);
    $inspectionId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_grounding_inspection_results (inspection_id, grounding_rod_id, rod_location, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($rods as $index => $rod) $stmt->execute([$inspectionId, $rod['id'], $rod['location'], $index + 1]);
    $pdo->commit();
    return $inspectionId;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof PDOException && $exception->getCode() === '23000') throw new RuntimeException('Questa procedura stagionale è già stata avviata.');
    throw $exception;
  }
}

function autocontrollo_grounding_send_report(PDO $pdo, int $inspectionId): int {
  $stmt = $pdo->prepare("SELECT i.*, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, u.email operator_email FROM autocontrollo_grounding_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE i.id=?");
  $stmt->execute([$inspectionId]);
  $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$inspection) return 0;
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_grounding_inspection_results WHERE inspection_id=? ORDER BY sort_order');
  $stmt->execute([$inspectionId]);
  $rows = '';
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $result) {
    $rows .= '<tr><td>' . htmlspecialchars($result['rod_location'], ENT_QUOTES, 'UTF-8') . '</td><td>Sì</td><td>Sì</td><td>' . date('d/m/Y H:i', strtotime($result['checked_at'])) . '</td></tr>';
  }
  $operator = trim((string)$inspection['operator_name']) ?: (string)$inspection['operator_email'];
  $type = $inspection['inspection_type'] === 'pre_apertura' ? 'Pre-apertura' : 'Post-chiusura';
  $html = '<h2>Autocontrollo Messa a Terra</h2><p><strong>Procedura:</strong> ' . $type . '<br><strong>Data prevista:</strong> ' . date('d/m/Y', strtotime($inspection['scheduled_date'])) . '<br><strong>Eseguita:</strong> ' . date('d/m/Y H:i', strtotime($inspection['completed_at'] ?: $inspection['started_at'])) . '<br><strong>Operatore:</strong> ' . htmlspecialchars($operator, ENT_QUOTES, 'UTF-8') . '</p><table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>Palina</th><th>Morsetto verificato</th><th>Spray disossidante</th><th>Data verifica</th></tr></thead><tbody>' . $rows . '</tbody></table>';
  $users = $pdo->query("SELECT email, dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll(PDO::FETCH_ASSOC);
  $sent = 0;
  foreach ($users as $recipient) {
    if (user_has_department($recipient, 'Amministrazione') && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) && send_mail($recipient['email'], 'Esito autocontrollo messa a terra', $html)) $sent++;
  }
  if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_grounding_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$inspectionId]);
  return $sent;
}

