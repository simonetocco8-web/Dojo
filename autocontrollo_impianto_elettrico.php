<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/autocontrollo_electrical.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) { http_response_code(403); exit('Accesso negato.'); }
$pdo = db();
ensure_autocontrollo_electrical_inspections_tables($pdo);
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$error = '';
$submittedDifferentials = null;
$submittedAnomaly = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
      $id = autocontrollo_electrical_start($pdo, (string)($_POST['inspection_type'] ?? ''), (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_impianto_elettrico.php?inspection=' . $id); exit;
    }
    if ($action === 'check') {
      $inspectionId = (int)($_POST['inspection_id'] ?? 0);
      $resultId = (int)($_POST['result_id'] ?? 0);
      $external = isset($_POST['external_check']);
      $differentialsOk = ($_POST['differentials_ok'] ?? '') === '1';
      $submittedDifferentials = (string)($_POST['differentials_ok'] ?? '');
      $answerProvided = in_array($_POST['differentials_ok'] ?? '', ['0', '1'], true);
      $anomaly = trim((string)($_POST['anomaly'] ?? ''));
      $submittedAnomaly = $anomaly;
      if (!$external) throw new InvalidArgumentException('Confermare di avere verificato lo stato esterno dei componenti.');
      if (!$answerProvided) throw new InvalidArgumentException('Indicare l’esito del test dei differenziali.');
      if (!$differentialsOk && $anomaly === '') throw new InvalidArgumentException('Descrivere brevemente il componente che non funziona regolarmente.');
      if ((function_exists('mb_strlen') ? mb_strlen($anomaly) : strlen($anomaly)) > 500) throw new InvalidArgumentException('La descrizione può contenere al massimo 500 caratteri.');
      $stmt = $pdo->prepare("UPDATE autocontrollo_electrical_inspection_results r JOIN autocontrollo_electrical_inspections i ON i.id=r.inspection_id SET r.external_check=1, r.differentials_ok=?, r.anomaly=?, r.checked_at=NOW() WHERE r.id=? AND r.inspection_id=? AND r.checked_at IS NULL AND i.status='in_corso'");
      $stmt->execute([$differentialsOk ? 1 : 0, $differentialsOk ? null : $anomaly, $resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Passaggio non valido o già registrato.');
      $remaining = $pdo->prepare('SELECT COUNT(*) FROM autocontrollo_electrical_inspection_results WHERE inspection_id=? AND checked_at IS NULL');
      $remaining->execute([$inspectionId]);
      if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare("UPDATE autocontrollo_electrical_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'")->execute([$inspectionId]);
        autocontrollo_electrical_send_report($pdo, $inspectionId);
        header('Location: ' . $base . '/autocontrollo_impianto_elettrico.php?completed=' . $inspectionId); exit;
      }
      header('Location: ' . $base . '/autocontrollo_impianto_elettrico.php?inspection=' . $inspectionId); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$schedule = autocontrollo_electrical_schedule($pdo);
$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0));
$activeInspection = null; $currentResult = null; $progress = [0, 0];
if ($inspectionId > 0) {
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_electrical_inspections WHERE id=?'); $stmt->execute([$inspectionId]); $activeInspection = $stmt->fetch();
  if ($activeInspection && $activeInspection['status'] === 'in_corso') {
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_electrical_inspection_results WHERE inspection_id=? AND checked_at IS NULL ORDER BY sort_order LIMIT 1'); $stmt->execute([$inspectionId]); $currentResult = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(*), SUM(checked_at IS NOT NULL) FROM autocontrollo_electrical_inspection_results WHERE inspection_id=?'); $stmt->execute([$inspectionId]); $progress = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
  }
}
$inspections = $pdo->query('SELECT i.*, u.email operator_email, COUNT(r.id) panel_count, COALESCE(SUM(r.differentials_ok=0),0) anomaly_count FROM autocontrollo_electrical_inspections i LEFT JOIN users u ON u.id=i.started_by LEFT JOIN autocontrollo_electrical_inspection_results r ON r.inspection_id=i.id GROUP BY i.id ORDER BY i.started_at DESC')->fetchAll();
$viewResults = [];
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
  $stmt = $pdo->prepare('SELECT panel_location, external_check, differentials_ok, anomaly, checked_at FROM autocontrollo_electrical_inspection_results WHERE inspection_id=? ORDER BY sort_order');
  $stmt->execute([$viewId]);
  $viewResults = $stmt->fetchAll();
}
$completedId = (int)($_GET['completed'] ?? 0);
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
$title = 'Autocontrollo Impianto Elettrico';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-lightning-charge me-1"></i>Impianto Elettrico</h1></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($completedId): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura completata. Il rapporto è stato inviato via email agli utenti del dipartimento Amministrazione.</div><?php endif; ?>

<?php if ($activeInspection && $currentResult): ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:720px"><div class="card-body p-3 p-md-4">
  <div class="d-flex justify-content-between mb-2"><span class="badge text-bg-primary">Quadro <?= $progress[1] + 1 ?> di <?= $progress[0] ?></span><span class="small text-muted"><?= (int)round(($progress[1] / max(1, $progress[0])) * 100) ?>%</span></div>
  <div class="progress mb-4" role="progressbar"><div class="progress-bar" style="width:<?= (int)round(($progress[1] / max(1, $progress[0])) * 100) ?>%"></div></div>
  <h2 class="h4 mb-3"><?= e($currentResult['panel_location']) ?></h2>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>"><input type="hidden" name="result_id" value="<?= (int)$currentResult['id'] ?>">
    <label class="card bg-light p-3 mb-3"><span class="form-check"><input class="form-check-input" type="checkbox" name="external_check" value="1" required><span class="form-check-label fw-semibold">Ho verificato lo stato esterno di tutti i componenti</span></span></label>
    <fieldset><legend class="h6">Premere il pulsante “TEST” su tutti i differenziali. Sono scattati tutti?</legend>
      <div class="row g-2 mb-3"><div class="col-6"><input class="btn-check" type="radio" name="differentials_ok" id="testYes" value="1" required <?= $submittedDifferentials === '1' ? 'checked' : '' ?>><label class="btn btn-outline-success w-100 py-3" for="testYes"><i class="bi bi-check-circle d-block fs-3"></i>Sì, tutti</label></div><div class="col-6"><input class="btn-check" type="radio" name="differentials_ok" id="testNo" value="0" required <?= $submittedDifferentials === '0' ? 'checked' : '' ?>><label class="btn btn-outline-danger w-100 py-3" for="testNo"><i class="bi bi-exclamation-triangle d-block fs-3"></i>No</label></div></div>
    </fieldset>
    <div id="anomalyBox" class="mb-3<?= $submittedDifferentials === '0' ? '' : ' d-none' ?>"><label for="anomaly" class="form-label fw-semibold">Descrivi il componente non funzionante <span class="text-danger">*</span></label><textarea class="form-control" id="anomaly" name="anomaly" maxlength="500" rows="3" placeholder="Es. Il differenziale generale non scatta" <?= $submittedDifferentials === '0' ? 'required' : '' ?>><?= e($submittedAnomaly) ?></textarea><div class="form-text">Obbligatorio quando uno o più differenziali non scattano.</div></div>
    <button class="btn btn-primary btn-lg w-100">Salva e passa al quadro successivo <i class="bi bi-arrow-right ms-1"></i></button>
  </form>
</div></div>
<script src="<?= e($base) ?>/assets/autocontrollo-electrical.js" defer></script>
<?php else: ?>
<div class="row g-3 mb-4">
<?php if (!$schedule): ?><div class="col-12"><div class="alert alert-warning">Configurare le date di apertura e chiusura della stagione nelle impostazioni di sistema.</div></div><?php endif; ?>
<?php foreach ($schedule as $type => $slot): $available = $today >= new DateTimeImmutable($slot['date'], new DateTimeZone('Europe/Rome')); ?>
  <div class="col-12 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-uppercase text-muted fw-semibold"><?= e($slot['label']) ?></div><div class="fs-4 fw-semibold mb-3"><?= e((new DateTimeImmutable($slot['date']))->format('d/m/Y')) ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="inspection_type" value="<?= e($type) ?>"><button class="btn btn-primary w-100" <?= $available ? '' : 'disabled' ?>><i class="bi bi-play-circle me-1"></i><?= $available ? 'Avvia procedura' : 'Non ancora disponibile' ?></button></form></div></div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Procedura</th><th>Operatore</th><th>Quadri</th><th>Esito</th><th></th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="6" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= $inspection['inspection_type'] === 'pre_apertura' ? 'Pre-apertura' : 'Post-chiusura' ?></td><td><?= e($inspection['operator_email'] ?? '—') ?></td><td><?= (int)$inspection['panel_count'] ?></td><td><?php if ($inspection['status'] === 'in_corso'): ?><span class="badge text-bg-warning">In corso</span><?php elseif ((int)$inspection['anomaly_count'] > 0): ?><span class="badge text-bg-danger"><?= (int)$inspection['anomaly_count'] ?> anomalie</span><?php else: ?><span class="badge text-bg-success">Regolare</span><?php endif; ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a> <?php if ($inspection['status'] === 'in_corso'): ?><a class="btn btn-sm btn-outline-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Quadro</th><th>Stato esterno</th><th>Test differenziali</th><th>Anomalia</th><th>Verificato alle</th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr><td><?= e($result['panel_location']) ?></td><td><?= $result['external_check'] === null ? '—' : 'Verificato' ?></td><td><?php if ($result['differentials_ok'] === null): ?>—<?php elseif ((int)$result['differentials_ok'] === 1): ?><span class="text-success fw-semibold">Regolare</span><?php else: ?><span class="text-danger fw-semibold">Anomalia</span><?php endif; ?></td><td><?= e($result['anomaly'] ?: '—') ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
