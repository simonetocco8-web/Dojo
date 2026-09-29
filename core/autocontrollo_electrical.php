<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

function autocontrollo_electrical_schedule(PDO $pdo): array {
  $range = get_summer_season_range($pdo);
  $timezone = new DateTimeZone('Europe/Rome');
  $start = !empty($range['start']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $range['start'], $timezone) : false;
  $end = !empty($range['end']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $range['end'], $timezone) : false;
  if (!$start || !$end || $start > $end) return [];

  return [
    'pre_apertura' => ['label' => 'Controllo pre-apertura', 'date' => $start->modify('-7 days')->format('Y-m-d')],
    'post_chiusura' => ['label' => 'Controllo post-chiusura', 'date' => $end->modify('+1 day')->format('Y-m-d')],
  ];
}

/**
 * Abbina ogni procedura alla scadenza stagionale a cui appartiene. La data
 * effettiva di avvio non modifica mai il tipo di controllo originario.
 */
function autocontrollo_electrical_schedule_inspections(array $schedule, array $inspections, array $range): array {
  $matched = [];
  foreach ($inspections as $inspection) {
    $type = (string)($inspection['inspection_type'] ?? '');
    if (!isset($schedule[$type])) continue;
    if (($inspection['season_start'] ?? null) !== ($range['start'] ?? null)
        || ($inspection['season_end'] ?? null) !== ($range['end'] ?? null)) continue;
    $matched[$type] = $inspection;
  }
  return $matched;
}

function autocontrollo_electrical_start(PDO $pdo, string $type, int $userId): int {
  $schedule = autocontrollo_electrical_schedule($pdo);
  if (!isset($schedule[$type])) throw new InvalidArgumentException('Momento di controllo non valido o stagione non configurata.');
  $range = get_summer_season_range($pdo);
  $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
  if ($today < new DateTimeImmutable($schedule[$type]['date'], new DateTimeZone('Europe/Rome'))) {
    throw new RuntimeException('La procedura sarà disponibile dalla data programmata.');
  }
  $panels = $pdo->query('SELECT id, installation_location FROM autocontrollo_electrical_panels ORDER BY installation_location, id')->fetchAll();
  if (!$panels) throw new RuntimeException('Configurare almeno un quadro elettrico prima di avviare la procedura.');

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO autocontrollo_electrical_inspections (season_start, season_end, inspection_type, scheduled_date, started_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$range['start'], $range['end'], $type, $schedule[$type]['date'], $userId]);
    $inspectionId = (int)$pdo->lastInsertId();
    $resultStmt = $pdo->prepare('INSERT INTO autocontrollo_electrical_inspection_results (inspection_id, panel_id, panel_location, sort_order) VALUES (?, ?, ?, ?)');
    foreach ($panels as $index => $panel) $resultStmt->execute([$inspectionId, $panel['id'], $panel['installation_location'], $index + 1]);
    $pdo->commit();
    return $inspectionId;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception instanceof PDOException && $exception->getCode() === '23000') throw new RuntimeException('Questa procedura stagionale è già stata avviata.');
    throw $exception;
  }
}

function autocontrollo_electrical_send_report(PDO $pdo, int $inspectionId): int {
  $stmt = $pdo->prepare('SELECT i.*, u.email operator_email FROM autocontrollo_electrical_inspections i LEFT JOIN users u ON u.id=i.started_by WHERE i.id=?');
  $stmt->execute([$inspectionId]);
  $inspection = $stmt->fetch();
  if (!$inspection) return 0;
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_electrical_inspection_results WHERE inspection_id=? ORDER BY sort_order');
  $stmt->execute([$inspectionId]);
  $results = $stmt->fetchAll();

  $rows = '';
  $hasAnomalies = false;
  foreach ($results as $result) {
    $ok = (int)$result['differentials_ok'] === 1;
    $hasAnomalies = $hasAnomalies || !$ok;
    $rows .= '<tr><td>' . htmlspecialchars($result['panel_location'], ENT_QUOTES, 'UTF-8') . '</td><td>Sì</td><td>' . ($ok ? 'Regolare' : '<strong style="color:#b02a37">ANOMALIA</strong>') . '</td><td>' . htmlspecialchars($result['anomaly'] ?: '—', ENT_QUOTES, 'UTF-8') . '</td></tr>';
  }
  $html = '<h2>Autocontrollo impianto elettrico</h2><p><strong>Data:</strong> ' . htmlspecialchars($inspection['completed_at'] ?: $inspection['started_at'], ENT_QUOTES, 'UTF-8') . '</p>'
    . ($hasAnomalies ? '<p style="color:#b02a37"><strong>ATTENZIONE: sono state rilevate anomalie.</strong></p>' : '<p><strong>Esito complessivo regolare.</strong></p>')
    . '<table border="1" cellpadding="7" cellspacing="0"><thead><tr><th>Quadro</th><th>Controllo esterno</th><th>Differenziali</th><th>Anomalia</th></tr></thead><tbody>' . $rows . '</tbody></table>';

  $users = $pdo->query("SELECT email, dipartimento FROM users WHERE is_active=1 AND deleted_at IS NULL AND email<>''")->fetchAll();
  $sent = 0;
  foreach ($users as $recipient) {
    if (user_has_department($recipient, 'Amministrazione') && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
      if (send_mail($recipient['email'], 'Esito autocontrollo impianto elettrico', $html)) $sent++;
    }
  }
  if ($sent > 0) $pdo->prepare('UPDATE autocontrollo_electrical_inspections SET email_sent_at=NOW() WHERE id=?')->execute([$inspectionId]);
  return $sent;
}
