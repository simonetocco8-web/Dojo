<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/settings.php';
require_once __DIR__ . '/core/autocontrollo_temperature.php';

require_login();
$user = current_user();
if (!$user || !user_has_department($user, 'Amministrazione')) { http_response_code(403); exit('Accesso negato.'); }
$env = require __DIR__ . '/config/env.php'; $base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db(); ensure_autocontrollo_temperature_inspections_tables($pdo);
$range = get_summer_season_range($pdo); $timezone = new DateTimeZone('Europe/Rome'); $today = new DateTimeImmutable('today', $timezone); $todayValue = $today->format('Y-m-d');
$seasonActive = !empty($range['start']) && !empty($range['end']) && $todayValue >= $range['start'] && $todayValue <= $range['end'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
  try {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
      $id = autocontrollo_temperature_start($pdo, $range, $todayValue, (string)($_POST['time_slot'] ?? ''), (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_temperature.php?inspection=' . $id); exit;
    }
    if ($action === 'check') {
      $inspectionId = (int)($_POST['inspection_id'] ?? 0); $resultId = (int)($_POST['result_id'] ?? 0); $compliant = (string)($_POST['is_compliant'] ?? '');
      if (!in_array($compliant, ['0', '1'], true)) throw new InvalidArgumentException('Indicare se la temperatura è conforme.');
      $stmt = $pdo->prepare("UPDATE autocontrollo_temperature_results r JOIN autocontrollo_temperature_inspections i ON i.id=r.inspection_id SET r.is_compliant=?, r.checked_at=NOW() WHERE r.id=? AND r.inspection_id=? AND r.checked_at IS NULL AND i.status='in_corso'");
      $stmt->execute([(int)$compliant, $resultId, $inspectionId]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('Passaggio non valido o già registrato.');
      $stmt = $pdo->prepare('SELECT COUNT(*) FROM autocontrollo_temperature_results WHERE inspection_id=? AND checked_at IS NULL'); $stmt->execute([$inspectionId]);
      if ((int)$stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE autocontrollo_temperature_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'")->execute([$inspectionId]);
        autocontrollo_temperature_send_report($pdo, $inspectionId);
        header('Location: ' . $base . '/autocontrollo_temperature.php?completed=' . $inspectionId); exit;
      }
      header('Location: ' . $base . '/autocontrollo_temperature.php?inspection=' . $inspectionId); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0)); $activeInspection = null; $currentResult = null; $activeResults = []; $progress = [0, 0];
if ($inspectionId > 0) {
  $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_inspections WHERE id=?'); $stmt->execute([$inspectionId]); $activeInspection = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($activeInspection && $activeInspection['status'] === 'in_corso') {
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$inspectionId]); $activeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? AND checked_at IS NULL ORDER BY sort_order LIMIT 1'); $stmt->execute([$inspectionId]); $currentResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT COUNT(*), SUM(checked_at IS NOT NULL) FROM autocontrollo_temperature_results WHERE inspection_id=?'); $stmt->execute([$inspectionId]); $progress = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
  }
}
$stmt = $pdo->prepare("SELECT i.*, u.email operator_email, TRIM(CONCAT_WS(' ', NULLIF(u.nome,''), NULLIF(u.cognome,''))) operator_name, COUNT(r.id) refrigerator_count, COALESCE(SUM(r.is_compliant=0),0) anomaly_count FROM autocontrollo_temperature_inspections i LEFT JOIN users u ON u.id=i.operator_id LEFT JOIN autocontrollo_temperature_results r ON r.inspection_id=i.id WHERE i.season_start=? AND i.season_end=? GROUP BY i.id ORDER BY i.inspection_date DESC, FIELD(i.time_slot,'pomeriggio','mattina'), i.started_at DESC");
$stmt->execute([$range['start'] ?? '', $range['end'] ?? '']); $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$startedToday = []; foreach ($inspections as $item) if ($item['inspection_date'] === $todayValue) $startedToday[$item['time_slot']] = true;
$viewId = (int)($_GET['view'] ?? 0); $viewResults = [];
if ($viewId > 0) { $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$viewId]); $viewResults = $stmt->fetchAll(PDO::FETCH_ASSOC); }
$title = 'Autocontrollo Temperature'; include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-thermometer-half me-1"></i>Temperature</h1></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura completata e rapporto inviato agli utenti Amministrazione.</div><?php endif; ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>La temperatura di un <strong>congelatore o di una cella</strong> deve rispettare la temperatura di esercizio + 3 °C (D.Lgs. 110/92); per i <strong>frigoriferi</strong> è ammessa una tolleranza temporanea di +1–2 °C.</div>

<?php if ($activeInspection && $currentResult): $percent = (int)round($progress[1] / max(1, $progress[0]) * 100); ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:720px"><div class="card-body p-3 p-md-4">
  <div class="d-flex justify-content-between mb-2"><span class="badge text-bg-primary">Frigo <?= $progress[1] + 1 ?> di <?= $progress[0] ?></span><span class="small text-muted"><?= $percent ?>%</span></div><div class="progress mb-4"><div class="progress-bar" style="width:<?= $percent ?>%"></div></div>
  <div class="text-muted text-uppercase small"><?= e(ucfirst($currentResult['appliance_type'])) ?> · temperatura di esercizio <?= e($currentResult['operating_temperature']) ?> °C</div><h2 class="display-6 mb-4">ID <?= e($currentResult['refrigerator_label']) ?></h2>
  <div class="table-responsive mb-4"><table class="table table-sm align-middle mb-0"><thead><tr><th>ID Frigo</th><th class="text-center">Mattina</th><th class="text-center">Pomeriggio</th></tr></thead><tbody><?php foreach ($activeResults as $result): $value = $result['checked_at'] === null ? '—' : ((int)$result['is_compliant'] ? 'Sì' : '<span class="text-danger fw-bold">NO</span>'); ?><tr class="<?= (int)$result['id'] === (int)$currentResult['id'] ? 'table-primary' : '' ?>"><td><?= e($result['refrigerator_label']) ?></td><td class="text-center"><?= $activeInspection['time_slot'] === 'mattina' ? $value : '—' ?></td><td class="text-center"><?= $activeInspection['time_slot'] === 'pomeriggio' ? $value : '—' ?></td></tr><?php endforeach; ?></tbody></table></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>"><input type="hidden" name="result_id" value="<?= (int)$currentResult['id'] ?>">
    <fieldset><legend class="h5">La temperatura è conforme?</legend><div class="row g-2 mb-3"><div class="col-6"><input class="btn-check" type="radio" name="is_compliant" id="temperatureYes" value="1" required><label class="btn btn-outline-success btn-lg w-100" for="temperatureYes">Sì</label></div><div class="col-6"><input class="btn-check" type="radio" name="is_compliant" id="temperatureNo" value="0" required><label class="btn btn-outline-danger btn-lg w-100" for="temperatureNo">No</label></div></div></fieldset>
    <button class="btn btn-primary btn-lg w-100">Salva e continua <i class="bi bi-arrow-right ms-1"></i></button>
  </form>
</div></div>
<?php else: ?>
<div class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5">Controlli di oggi · <?= e($today->format('d/m/Y')) ?></h2>
<?php if (!$seasonActive): ?><div class="alert alert-warning mb-0">La giornata corrente non rientra nelle date di apertura e chiusura della stagione configurata.</div>
<?php else: ?><div class="row g-3"><?php foreach (['mattina'=>'Mattina','pomeriggio'=>'Pomeriggio'] as $slot=>$label): ?><div class="col-12 col-sm-6"><div class="border rounded p-3 h-100"><div class="fw-semibold mb-2"><i class="bi <?= $slot === 'mattina' ? 'bi-sunrise' : 'bi-sunset' ?> me-1"></i><?= $label ?></div><?php if (isset($startedToday[$slot])): ?><span class="badge text-bg-success">Procedura già avviata</span><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="time_slot" value="<?= e($slot) ?>"><button class="btn btn-primary w-100"><i class="bi bi-play-circle me-1"></i>Avvia controllo</button></form><?php endif; ?></div></div><?php endforeach; ?></div><?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Fascia</th><th>Operatore</th><th>ID Frigo</th><th>Mattina</th><th>Pomeriggio</th><th>Anomalie da risolvere</th><th></th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="8" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): $anomalies = (int)$inspection['anomaly_count']; ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= e(ucfirst($inspection['time_slot'])) ?></td><td><?= e($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?></td><td><?= (int)$inspection['refrigerator_count'] ?> frigoriferi</td><td><?= $inspection['time_slot'] === 'mattina' ? ($inspection['status'] === 'completata' ? 'Sì' : 'In corso') : '—' ?></td><td><?= $inspection['time_slot'] === 'pomeriggio' ? ($inspection['status'] === 'completata' ? 'Sì' : 'In corso') : '—' ?></td><td><?php if ($anomalies): ?><span class="badge text-bg-danger"><?= $anomalies ?> <?= $anomalies === 1 ? 'anomalia' : 'anomalie' ?></span><?php else: ?><span class="badge text-bg-success">Nessuna</span><?php endif; ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a><?php if ($inspection['status'] === 'in_corso'): ?> <a class="btn btn-sm btn-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>ID Frigo</th><th>Tipologia</th><th>Temperatura esercizio</th><th>Conforme</th><th>Verificata</th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr class="<?= $result['is_compliant'] !== null && !(int)$result['is_compliant'] ? 'table-danger' : '' ?>"><td><?= e($result['refrigerator_label']) ?></td><td><?= e(ucfirst($result['appliance_type'])) ?></td><td><?= e($result['operating_temperature']) ?> °C</td><td><?= $result['is_compliant'] === null ? '—' : ((int)$result['is_compliant'] ? 'Sì' : '<strong>NO — Anomalia</strong>') ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
