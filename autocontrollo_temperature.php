<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/autocontrollo_temperature.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) { http_response_code(403); exit('Accesso negato.'); }
$pdo = db();
ensure_autocontrollo_temperature_tables($pdo);
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
      $id = autocontrollo_temperature_start($pdo, (string)($_POST['period'] ?? ''), (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_temperature.php?inspection=' . $id); exit;
    }
    if ($action === 'check') {
      $inspectionId = (int)($_POST['inspection_id'] ?? 0); $resultId = (int)($_POST['result_id'] ?? 0);
      $answer = (string)($_POST['is_compliant'] ?? '');
      if (!in_array($answer, ['0', '1'], true)) throw new InvalidArgumentException('Indicare se la temperatura è conforme.');
      $stmt = $pdo->prepare("UPDATE autocontrollo_temperature_results r JOIN autocontrollo_temperature_inspections i ON i.id=r.inspection_id SET r.is_compliant=?, r.checked_at=NOW() WHERE r.id=? AND r.inspection_id=? AND r.checked_at IS NULL AND i.status='in_corso'");
      $stmt->execute([(int)$answer, $resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Passaggio non valido o già registrato.');
      $remaining = $pdo->prepare('SELECT COUNT(*) FROM autocontrollo_temperature_results WHERE inspection_id=? AND checked_at IS NULL');
      $remaining->execute([$inspectionId]);
      if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare("UPDATE autocontrollo_temperature_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'")->execute([$inspectionId]);
        autocontrollo_temperature_send_report($pdo, $inspectionId);
        header('Location: ' . $base . '/autocontrollo_temperature.php?completed=' . $inspectionId); exit;
      }
      header('Location: ' . $base . '/autocontrollo_temperature.php?inspection=' . $inspectionId); exit;
    }
    if ($action === 'resolve_anomaly') {
      $resultId = (int)($_POST['result_id'] ?? 0); $inspectionId = (int)($_POST['inspection_id'] ?? 0);
      $stmt = $pdo->prepare('UPDATE autocontrollo_temperature_results SET anomaly_resolved_at=NOW(), anomaly_resolved_by=? WHERE id=? AND inspection_id=? AND is_compliant=0 AND anomaly_resolved_at IS NULL');
      $stmt->execute([(int)$user['id'], $resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Anomalia non trovata o già risolta.');
      header('Location: ' . $base . '/autocontrollo_temperature.php?view=' . $inspectionId . '#dettaglio'); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0));
$activeInspection = null; $currentResult = null; $progress = [0, 0];
if ($inspectionId > 0) {
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_inspections WHERE id=?'); $stmt->execute([$inspectionId]); $activeInspection = $stmt->fetch();
  if ($activeInspection && $activeInspection['status'] === 'in_corso') {
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? AND checked_at IS NULL ORDER BY sort_order LIMIT 1'); $stmt->execute([$inspectionId]); $currentResult = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(*), SUM(checked_at IS NOT NULL) FROM autocontrollo_temperature_results WHERE inspection_id=?'); $stmt->execute([$inspectionId]); $progress = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
  }
}
$inspections = $pdo->query('SELECT i.*, u.email operator_email, COUNT(r.id) refrigerator_count, COALESCE(SUM(r.is_compliant=0),0) anomaly_count, COALESCE(SUM(r.is_compliant=0 AND r.anomaly_resolved_at IS NULL),0) unresolved_count FROM autocontrollo_temperature_inspections i LEFT JOIN users u ON u.id=i.started_by LEFT JOIN autocontrollo_temperature_results r ON r.inspection_id=i.id GROUP BY i.id ORDER BY i.started_at DESC')->fetchAll();
$todayString = (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$todayRows = $pdo->prepare("SELECT f.refrigerator_identifier, f.location, MAX(CASE WHEN i.period='mattina' THEN r.is_compliant END) morning, MAX(CASE WHEN i.period='pomeriggio' THEN r.is_compliant END) afternoon FROM autocontrollo_refrigerators f LEFT JOIN autocontrollo_temperature_results r ON r.refrigerator_id=f.id LEFT JOIN autocontrollo_temperature_inspections i ON i.id=r.inspection_id AND i.inspection_date=? GROUP BY f.id, f.refrigerator_identifier, f.location ORDER BY f.refrigerator_identifier");
$todayRows->execute([$todayString]); $todayRows = $todayRows->fetchAll();
$todayInspections = [];
foreach ($inspections as $inspection) if ($inspection['inspection_date'] === $todayString) $todayInspections[$inspection['period']] = $inspection;
$viewId = (int)($_GET['view'] ?? 0); $viewResults = [];
if ($viewId > 0) { $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$viewId]); $viewResults = $stmt->fetchAll(); }
$title = 'Autocontrollo Temperature';
include __DIR__ . '/partials/header.php';
function temperature_result_badge($value): string { if ($value === null) return '<span class="text-muted">—</span>'; return (int)$value === 1 ? '<span class="badge text-bg-success">SÌ</span>' : '<span class="badge text-bg-danger">NO</span>'; }
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-thermometer-half me-1"></i>Temperature</h1></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success">Procedura completata. Il rapporto è stato inviato agli utenti del dipartimento Amministrazione.</div><?php endif; ?>

<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>La temperatura di un congelatore o di una cella deve rispettare la temperatura di esercizio <strong>+ 3 °C (D.Lgs. 110/92)</strong>; per i frigoriferi è ammessa una tolleranza temporanea di <strong>+ 1-2 °C</strong>.</div>

<?php if ($activeInspection && $currentResult): ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:680px"><div class="card-body p-3 p-md-4">
  <div class="d-flex justify-content-between mb-2"><span class="badge text-bg-primary">Frigo <?= $progress[1] + 1 ?> di <?= $progress[0] ?></span><span class="text-muted small"><?= ucfirst(e($activeInspection['period'])) ?></span></div>
  <div class="progress mb-4"><div class="progress-bar" style="width:<?= (int)round($progress[1] / max(1, $progress[0]) * 100) ?>%"></div></div>
  <h2 class="h4 mb-1"><?= e($currentResult['refrigerator_identifier']) ?></h2><p class="text-muted mb-4"><i class="bi bi-geo-alt"></i> <?= e($currentResult['refrigerator_location']) ?></p>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>"><input type="hidden" name="result_id" value="<?= (int)$currentResult['id'] ?>">
    <fieldset><legend class="h6">La temperatura rilevata è conforme?</legend><div class="row g-2 mb-3"><div class="col-6"><input class="btn-check" type="radio" name="is_compliant" id="temperatureYes" value="1" required><label class="btn btn-outline-success w-100 py-4" for="temperatureYes"><i class="bi bi-check-circle d-block fs-2"></i>SÌ</label></div><div class="col-6"><input class="btn-check" type="radio" name="is_compliant" id="temperatureNo" value="0" required><label class="btn btn-outline-danger w-100 py-4" for="temperatureNo"><i class="bi bi-x-circle d-block fs-2"></i>NO</label></div></div></fieldset>
    <button class="btn btn-primary btn-lg w-100">Salva e continua <i class="bi bi-arrow-right ms-1"></i></button>
  </form>
</div></div>
<?php else: ?>
<div class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Controlli di oggi</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID Frigo</th><th>Location</th><th>Mattina</th><th>Pomeriggio</th></tr></thead><tbody>
<?php if (!$todayRows): ?><tr><td colspan="4" class="text-center text-muted py-4">Configurare i frigoriferi nei setting.</td></tr><?php endif; ?>
<?php foreach ($todayRows as $row): ?><tr><td class="fw-semibold"><?= e($row['refrigerator_identifier']) ?></td><td><?= e($row['location']) ?></td><td><?= temperature_result_badge($row['morning']) ?></td><td><?= temperature_result_badge($row['afternoon']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top"><div class="row g-2"><?php foreach (['mattina' => 'Mattina', 'pomeriggio' => 'Pomeriggio'] as $period => $label): $existing = $todayInspections[$period] ?? null; ?><div class="col-12 col-md-6"><?php if ($existing): ?><a class="btn <?= $existing['status'] === 'in_corso' ? 'btn-primary' : 'btn-outline-success' ?> w-100" href="?<?= $existing['status'] === 'in_corso' ? 'inspection' : 'view' ?>=<?= (int)$existing['id'] ?>"><?= $existing['status'] === 'in_corso' ? 'Continua controllo' : 'Visualizza controllo' ?> <?= e($label) ?></a><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="period" value="<?= e($period) ?>"><button class="btn btn-primary w-100"><i class="bi bi-play-circle me-1"></i>Avvia controllo <?= e($label) ?></button></form><?php endif; ?></div><?php endforeach; ?></div></div></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Fascia</th><th>Operatore</th><th>Frigoriferi</th><th>Esito</th><th></th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= e(ucfirst($inspection['period'])) ?></td><td><?= e($inspection['operator_email'] ?: '—') ?></td><td><?= (int)$inspection['refrigerator_count'] ?></td><td><?php if ($inspection['status'] === 'in_corso'): ?><span class="badge text-bg-warning">In corso</span><?php elseif ((int)$inspection['unresolved_count'] > 0): ?><span class="badge text-bg-danger"><?= (int)$inspection['unresolved_count'] ?> anomalie da risolvere</span><?php elseif ((int)$inspection['anomaly_count'] > 0): ?><span class="badge text-bg-success">Anomalie risolte</span><?php else: ?><span class="badge text-bg-success">Regolare</span><?php endif; ?></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a><?php if ($inspection['status'] === 'in_corso'): ?> <a class="btn btn-sm btn-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID Frigo</th><th>Location</th><th>Conforme</th><th>Verificato alle</th><th></th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr><td><?= e($result['refrigerator_identifier']) ?></td><td><?= e($result['refrigerator_location']) ?></td><td><?= temperature_result_badge($result['is_compliant']) ?><?php if ($result['anomaly_resolved_at']): ?> <span class="badge text-bg-success">Risolta</span><?php endif; ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td><td class="text-end"><?php if ((int)$result['is_compliant'] === 0 && !$result['anomaly_resolved_at']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resolve_anomaly"><input type="hidden" name="inspection_id" value="<?= $viewId ?>"><input type="hidden" name="result_id" value="<?= (int)$result['id'] ?>"><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Segna risolta</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
