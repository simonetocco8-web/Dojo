<?php

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/autocontrollo_rodent.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) { http_response_code(403); exit('Accesso negato.'); }
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_autocontrollo_rodent_inspections_tables($pdo);
$range = get_summer_season_range($pdo);
$schedule = autocontrollo_rodent_schedule($range);
$stmt = $pdo->prepare('SELECT scheduled_date FROM autocontrollo_rodent_inspections WHERE season_start=? AND season_end=? ORDER BY scheduled_date');
$stmt->execute([$range['start'] ?? '', $range['end'] ?? '']);
$nextDate = autocontrollo_rodent_next_date($schedule, $stmt->fetchAll(PDO::FETCH_COLUMN));
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Rome'));
$error = '';
$submitted = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
      if (!$nextDate) throw new RuntimeException('Non risultano controlli da avviare.');
      $id = autocontrollo_rodent_start($pdo, $range, $nextDate, (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_derattizzazione.php?inspection=' . $id); exit;
    }
    if ($action === 'check') {
      $inspectionId = (int)($_POST['inspection_id'] ?? 0);
      $resultId = (int)($_POST['result_id'] ?? 0);
      $baitPresentRaw = (string)($_POST['bait_present'] ?? '');
      $baitEatenRaw = (string)($_POST['bait_eaten'] ?? '');
      $baitReplacedRaw = (string)($_POST['bait_replaced'] ?? '');
      if (!in_array($baitPresentRaw, ['0', '1'], true)) throw new InvalidArgumentException('Indicare se è presente un’esca.');
      if ($baitPresentRaw === '1' && !in_array($baitEatenRaw, ['0', '1'], true)) throw new InvalidArgumentException('Indicare se l’esca risulta mangiata.');
      if (!in_array($baitReplacedRaw, ['0', '1'], true)) throw new InvalidArgumentException('Indicare se l’esca è stata sostituita.');
      $stmt = $pdo->prepare("UPDATE autocontrollo_rodent_inspection_results r JOIN autocontrollo_rodent_inspections i ON i.id=r.inspection_id SET r.bait_present=?, r.bait_eaten=?, r.bait_replaced=?, r.checked_at=NOW() WHERE r.id=? AND r.inspection_id=? AND r.checked_at IS NULL AND i.status='in_corso'");
      $stmt->execute([(int)$baitPresentRaw, $baitPresentRaw === '1' ? (int)$baitEatenRaw : null, (int)$baitReplacedRaw, $resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Passaggio non valido o già registrato.');
      $stmt = $pdo->prepare('SELECT COUNT(*) FROM autocontrollo_rodent_inspection_results WHERE inspection_id=? AND checked_at IS NULL');
      $stmt->execute([$inspectionId]);
      if ((int)$stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE autocontrollo_rodent_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'")->execute([$inspectionId]);
        autocontrollo_rodent_send_report($pdo, $inspectionId);
        header('Location: ' . $base . '/autocontrollo_derattizzazione.php?completed=' . $inspectionId); exit;
      }
      header('Location: ' . $base . '/autocontrollo_derattizzazione.php?inspection=' . $inspectionId); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0));
if ($inspectionId <= 0) {
  $stmt = $pdo->prepare("SELECT id FROM autocontrollo_rodent_inspections WHERE season_start=? AND season_end=? AND status='in_corso' ORDER BY started_at LIMIT 1");
  $stmt->execute([$range['start'] ?? '', $range['end'] ?? '']);
  $inspectionId = (int)($stmt->fetchColumn() ?: 0);
}
$activeInspection = null; $currentResult = null; $progress = [0, 0];
if ($inspectionId > 0) {
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_rodent_inspections WHERE id=?'); $stmt->execute([$inspectionId]); $activeInspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($activeInspection && $activeInspection['status'] === 'in_corso') {
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_rodent_inspection_results WHERE inspection_id=? AND checked_at IS NULL ORDER BY sort_order LIMIT 1'); $stmt->execute([$inspectionId]); $currentResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT COUNT(*), SUM(checked_at IS NOT NULL) FROM autocontrollo_rodent_inspection_results WHERE inspection_id=?'); $stmt->execute([$inspectionId]); $progress = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
  }
}
$stmt = $pdo->prepare("SELECT i.*, u.email operator_email, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, COUNT(r.id) trap_count, COALESCE(SUM(r.bait_eaten=1),0) eaten_count, COALESCE(SUM(r.bait_replaced=1),0) replaced_count FROM autocontrollo_rodent_inspections i LEFT JOIN users u ON u.id=i.operator_id LEFT JOIN autocontrollo_rodent_inspection_results r ON r.inspection_id=i.id WHERE i.season_start=? AND i.season_end=? GROUP BY i.id ORDER BY i.scheduled_date DESC");
$stmt->execute([$range['start'] ?? '', $range['end'] ?? '']);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$viewId = (int)($_GET['view'] ?? 0); $viewResults = [];
if ($viewId > 0) { $stmt = $pdo->prepare('SELECT * FROM autocontrollo_rodent_inspection_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$viewId]); $viewResults = $stmt->fetchAll(PDO::FETCH_ASSOC); }
$title = 'Autocontrollo Derattizzazione';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-bug me-1"></i>Derattizzazione</h1></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura completata e rapporto inviato agli utenti Amministrazione.</div><?php endif; ?>

<?php if ($activeInspection && $currentResult): ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:720px"><div class="card-body p-3 p-md-4"><div class="d-flex justify-content-between mb-2"><span class="badge text-bg-primary">Trappola <?= $progress[1] + 1 ?> di <?= $progress[0] ?></span><span class="small text-muted"><?= (int)round($progress[1] / max(1, $progress[0]) * 100) ?>%</span></div><div class="progress mb-4"><div class="progress-bar" style="width:<?= (int)round($progress[1] / max(1, $progress[0]) * 100) ?>%"></div></div><h2 class="h4 mb-3"><?= e($currentResult['trap_location']) ?></h2>
<form method="post" id="rodentInspectionForm"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>"><input type="hidden" name="result_id" value="<?= (int)$currentResult['id'] ?>">
<?php $questions = [['bait_present','È già presente un’esca?'],['bait_eaten','L’esca risulta mangiata?'],['bait_replaced','L’esca è stata sostituita?']]; foreach ($questions as [$name,$label]): ?><fieldset class="mb-3 <?= $name === 'bait_eaten' && ($submitted['bait_present'] ?? '') !== '1' ? 'd-none' : '' ?>" <?= $name === 'bait_eaten' ? 'id="baitEatenQuestion"' : '' ?>><legend class="h6"><?= e($label) ?></legend><div class="row g-2"><div class="col-6"><input class="btn-check" type="radio" name="<?= e($name) ?>" id="<?= e($name) ?>Yes" value="1" <?= ($submitted[$name] ?? '') === '1' ? 'checked' : '' ?> required><label class="btn btn-outline-success w-100 py-2" for="<?= e($name) ?>Yes">Sì</label></div><div class="col-6"><input class="btn-check" type="radio" name="<?= e($name) ?>" id="<?= e($name) ?>No" value="0" <?= ($submitted[$name] ?? '') === '0' ? 'checked' : '' ?> required><label class="btn btn-outline-secondary w-100 py-2" for="<?= e($name) ?>No">No</label></div></div></fieldset><?php endforeach; ?>
<button class="btn btn-primary btn-lg w-100">Salva e passa alla trappola successiva <i class="bi bi-arrow-right ms-1"></i></button></form></div></div>
<?php else: ?>
<div class="card shadow-sm mb-4"><div class="card-body"><?php if (!$schedule): ?><div class="alert alert-warning mb-0">Configurare le date di apertura e chiusura della stagione.</div><?php elseif ($nextDate === null): ?><div class="alert alert-success mb-0">Tutte le procedure quindicinali della stagione sono state avviate.</div><?php else: $available = new DateTimeImmutable($nextDate) <= $today; ?><div class="small text-muted text-uppercase fw-semibold">Prossima procedura prevista</div><div class="fs-4 fw-semibold mb-3"><?= e((new DateTimeImmutable($nextDate))->format('d/m/Y')) ?></div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="bi bi-play-circle me-1"></i><?= $available ? 'Avvia procedura' : 'Non ancora disponibile' ?></button></form><?php endif; ?></div></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Scadenza</th><th>Operatore</th><th>Trappole</th><th>Esche mangiate</th><th>Esche sostituite</th><th>Stato</th><th></th></tr></thead><tbody><?php if (!$inspections): ?><tr><td colspan="8" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?><?php foreach ($inspections as $inspection): ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= e((new DateTimeImmutable($inspection['scheduled_date']))->format('d/m/Y')) ?></td><td><?= e($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?></td><td><?= (int)$inspection['trap_count'] ?></td><td><?= (int)$inspection['eaten_count'] ?></td><td><?= (int)$inspection['replaced_count'] ?></td><td><span class="badge <?= $inspection['status'] === 'completata' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $inspection['status'] === 'completata' ? 'Completata' : 'In corso' ?></span></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a><?php if ($inspection['status'] === 'in_corso'): ?> <a class="btn btn-sm btn-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Trappola</th><th>Esca presente</th><th>Esca mangiata</th><th>Esca sostituita</th><th>Verificata</th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr><td><?= e($result['trap_location']) ?></td><td><?= $result['bait_present'] === null ? '—' : ((int)$result['bait_present'] ? 'Sì' : 'No') ?></td><td><?= $result['bait_eaten'] === null ? '—' : ((int)$result['bait_eaten'] ? 'Sì' : 'No') ?></td><td><?= $result['bait_replaced'] === null ? '—' : ((int)$result['bait_replaced'] ? 'Sì' : 'No') ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<script src="<?= e($base) ?>/assets/autocontrollo-rodent.js" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
