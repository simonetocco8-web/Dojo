<?php
require_once __DIR__ . '/core/autocontrollo_settings.php';

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/autocontrollo_grounding.php';

require_login();
$user = current_user();
if (!$user || !autocontrollo_user_can_perform($user, 'grounding')) { http_response_code(403); exit('Accesso negato.'); }
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_autocontrollo_grounding_inspections_tables($pdo);
$range = get_summer_season_range($pdo);
$schedule = autocontrollo_grounding_schedule($range);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
      $id = autocontrollo_grounding_start($pdo, $range, (string)($_POST['inspection_type'] ?? ''), (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_messa_a_terra.php?inspection=' . $id); exit;
    }
    if ($action === 'check') {
      if (!isset($_POST['clamp_checked'])) throw new InvalidArgumentException('Confermare la verifica dello stato esterno del morsetto.');
      if (!isset($_POST['antioxidant_applied'])) throw new InvalidArgumentException('Confermare l’applicazione dello spray disossidante.');
      $inspectionId = (int)($_POST['inspection_id'] ?? 0); $resultId = (int)($_POST['result_id'] ?? 0);
      $stmt = $pdo->prepare("UPDATE autocontrollo_grounding_inspection_results r JOIN autocontrollo_grounding_inspections i ON i.id=r.inspection_id SET r.clamp_checked=1, r.antioxidant_applied=1, r.checked_at=NOW() WHERE r.id=? AND r.inspection_id=? AND r.checked_at IS NULL AND i.status='in_corso'");
      $stmt->execute([$resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Passaggio non valido o già registrato.');
      $stmt = $pdo->prepare('SELECT COUNT(*) FROM autocontrollo_grounding_inspection_results WHERE inspection_id=? AND checked_at IS NULL'); $stmt->execute([$inspectionId]);
      if ((int)$stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE autocontrollo_grounding_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'")->execute([$inspectionId]);
        autocontrollo_grounding_send_report($pdo, $inspectionId);
        header('Location: ' . $base . '/autocontrollo_messa_a_terra.php?completed=' . $inspectionId); exit;
      }
      header('Location: ' . $base . '/autocontrollo_messa_a_terra.php?inspection=' . $inspectionId); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$stmt = $pdo->prepare("SELECT i.*, u.email operator_email, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, COUNT(r.id) rod_count FROM autocontrollo_grounding_inspections i LEFT JOIN users u ON u.id=i.operator_id LEFT JOIN autocontrollo_grounding_inspection_results r ON r.inspection_id=i.id WHERE i.season_start=? AND i.season_end=? GROUP BY i.id ORDER BY i.started_at DESC");
$stmt->execute([$range['start'] ?? '', $range['end'] ?? '']); $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$scheduledInspections = [];
foreach ($inspections as $inspection) $scheduledInspections[$inspection['inspection_type']] = $inspection;
$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0));
if ($inspectionId <= 0) { foreach ($inspections as $inspection) if ($inspection['status'] === 'in_corso') { $inspectionId = (int)$inspection['id']; break; } }
$activeInspection = null; $currentResult = null; $progress = [0, 0];
if ($inspectionId > 0) {
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_grounding_inspections WHERE id=?'); $stmt->execute([$inspectionId]); $activeInspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($activeInspection && $activeInspection['status'] === 'in_corso') {
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_grounding_inspection_results WHERE inspection_id=? AND checked_at IS NULL ORDER BY sort_order LIMIT 1'); $stmt->execute([$inspectionId]); $currentResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT COUNT(*), SUM(checked_at IS NOT NULL) FROM autocontrollo_grounding_inspection_results WHERE inspection_id=?'); $stmt->execute([$inspectionId]); $progress = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
  }
}
$viewId = (int)($_GET['view'] ?? 0); $viewResults = [];
if ($viewId > 0) { $stmt = $pdo->prepare('SELECT * FROM autocontrollo_grounding_inspection_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$viewId]); $viewResults = $stmt->fetchAll(PDO::FETCH_ASSOC); }
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
$title = 'Autocontrollo Messa a Terra';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-plug me-1"></i>Messa a Terra</h1></div><a class="btn btn-outline-danger" href="<?= e($base) ?>/reports/autocontrollo_grounding_pdf.php?season=1"><i class="bi bi-file-earmark-pdf me-1"></i>Esporta PDF stagione</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura completata e rapporto inviato agli utenti Amministrazione.</div><?php endif; ?>

<?php if ($activeInspection && $currentResult): ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:720px"><div class="card-body p-3 p-md-4"><div class="d-flex justify-content-between mb-2"><span class="badge text-bg-primary">Palina <?= $progress[1] + 1 ?> di <?= $progress[0] ?></span><span class="small text-muted"><?= (int)round($progress[1] / max(1, $progress[0]) * 100) ?>%</span></div><div class="progress mb-4"><div class="progress-bar" style="width:<?= (int)round($progress[1] / max(1, $progress[0]) * 100) ?>%"></div></div><h2 class="h4 mb-3"><?= e($currentResult['rod_location']) ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>"><input type="hidden" name="result_id" value="<?= (int)$currentResult['id'] ?>"><label class="card bg-light p-3 mb-3"><span class="form-check"><input class="form-check-input" type="checkbox" name="clamp_checked" value="1" required><span class="form-check-label fw-semibold">Ho verificato lo stato esterno del morsetto</span></span></label><label class="card bg-light p-3 mb-3"><span class="form-check"><input class="form-check-input" type="checkbox" name="antioxidant_applied" value="1" required><span class="form-check-label fw-semibold">Ho spruzzato lo spray disossidante</span></span></label><button class="btn btn-primary btn-lg w-100">Conferma e passa alla palina successiva <i class="bi bi-arrow-right ms-1"></i></button></form></div></div>
<?php else: ?>
<div class="row g-3 mb-4"><?php if (!$schedule): ?><div class="col-12"><div class="alert alert-warning">Configurare le date di apertura e chiusura della stagione.</div></div><?php endif; ?><?php foreach ($schedule as $type => $slot): $inspection = $scheduledInspections[$type] ?? null; $available = new DateTimeImmutable($slot['date']) <= $today; ?><div class="col-12 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold"><?= e($slot['label']) ?></div><div class="fs-4 fw-semibold mb-3"><?= e((new DateTimeImmutable($slot['date']))->format('d/m/Y')) ?></div><?php if ($inspection): ?><div class="alert <?= $inspection['status'] === 'completata' ? 'alert-success' : 'alert-warning' ?> py-2"><strong><?= $inspection['status'] === 'completata' ? 'Eseguita' : 'In corso' ?></strong> il <?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></div><a class="btn btn-outline-secondary w-100" href="?<?= $inspection['status'] === 'in_corso' ? 'inspection' : 'view' ?>=<?= (int)$inspection['id'] ?><?= $inspection['status'] === 'completata' ? '#dettaglio' : '' ?>"><?= $inspection['status'] === 'in_corso' ? 'Continua procedura' : 'Visualizza controllo' ?></a><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="inspection_type" value="<?= e($type) ?>"><button class="btn btn-primary w-100" <?= $available ? '' : 'disabled' ?>><?= $available ? 'Avvia procedura' : 'Non ancora disponibile' ?></button></form><?php endif; ?></div></div></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Procedura</th><th>Data prevista</th><th>Operatore</th><th>Paline</th><th>Stato</th><th></th></tr></thead><tbody><?php if (!$inspections): ?><tr><td colspan="7" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?><?php foreach ($inspections as $inspection): ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= $inspection['inspection_type'] === 'pre_apertura' ? 'Pre-apertura' : 'Post-chiusura' ?></td><td><?= e((new DateTimeImmutable($inspection['scheduled_date']))->format('d/m/Y')) ?></td><td><?= e($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?></td><td><?= (int)$inspection['rod_count'] ?></td><td><span class="badge <?= $inspection['status'] === 'completata' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $inspection['status'] === 'completata' ? 'Completata' : 'In corso' ?></span></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-danger" href="<?= e($base) ?>/reports/autocontrollo_grounding_pdf.php?id=<?= (int)$inspection['id'] ?>" title="Esporta procedura in PDF" aria-label="Esporta procedura messa a terra #<?= (int)$inspection['id'] ?> in PDF"><i class="bi bi-file-earmark-pdf"></i></a> <a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a><?php if ($inspection['status'] === 'in_corso'): ?> <a class="btn btn-sm btn-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Palina</th><th>Morsetto verificato</th><th>Spray disossidante</th><th>Data verifica</th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr><td><?= e($result['rod_location']) ?></td><td><?= (int)$result['clamp_checked'] === 1 ? 'Sì' : '—' ?></td><td><?= (int)$result['antioxidant_applied'] === 1 ? 'Sì' : '—' ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
