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
      $id = autocontrollo_temperature_start($pdo, $range, (string)($_POST['inspection_date'] ?? ''), (string)($_POST['time_slot'] ?? ''), (int)$user['id']);
      header('Location: ' . $base . '/autocontrollo_temperature.php?inspection=' . $id); exit;
    }
    if ($action === 'save_all') {
      $inspectionId = (int)($_POST['inspection_id'] ?? 0); $answers = $_POST['compliance'] ?? [];
      if (!is_array($answers)) throw new InvalidArgumentException('Rilevazioni non valide.');
      $stmt = $pdo->prepare("SELECT r.id FROM autocontrollo_temperature_results r JOIN autocontrollo_temperature_inspections i ON i.id=r.inspection_id WHERE r.inspection_id=? AND i.status='in_corso' ORDER BY r.sort_order");
      $stmt->execute([$inspectionId]); $resultIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
      if (!$resultIds) throw new RuntimeException('Procedura non valida o già completata.');
      foreach ($resultIds as $resultId) if (!isset($answers[$resultId]) || !in_array((string)$answers[$resultId], ['0', '1'], true)) throw new InvalidArgumentException('Specificare Sì o No per tutti i frigoriferi.');
      $pdo->beginTransaction();
      try {
        $update = $pdo->prepare('UPDATE autocontrollo_temperature_results SET is_compliant=?, checked_at=NOW() WHERE id=? AND inspection_id=?');
        foreach ($resultIds as $resultId) $update->execute([(int)$answers[$resultId], $resultId, $inspectionId]);
        $stmt = $pdo->prepare("UPDATE autocontrollo_temperature_inspections SET status='completata', completed_at=NOW() WHERE id=? AND status='in_corso'"); $stmt->execute([$inspectionId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('La procedura è già stata completata.');
        $pdo->commit();
      } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
      autocontrollo_temperature_send_report($pdo, $inspectionId);
      header('Location: ' . $base . '/autocontrollo_temperature.php?completed=' . $inspectionId); exit;
    }
    throw new InvalidArgumentException('Azione non valida.');
  } catch (Throwable $exception) { $error = $exception->getMessage(); }
}

$inspectionId = (int)($_GET['inspection'] ?? ($_POST['inspection_id'] ?? 0)); $activeInspection = null; $currentResult = null; $activeResults = []; $progress = [0, 0];
if ($inspectionId <= 0) { $stmt = $pdo->prepare("SELECT id FROM autocontrollo_temperature_inspections WHERE season_start=? AND season_end=? AND status='in_corso' ORDER BY inspection_date, FIELD(time_slot,'mattina','pomeriggio') LIMIT 1"); $stmt->execute([$range['start'] ?? '', $range['end'] ?? '']); $inspectionId = (int)($stmt->fetchColumn() ?: 0); }
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
$nextDue = autocontrollo_temperature_next_due($range, $inspections);
$viewId = (int)($_GET['view'] ?? 0); $viewResults = [];
if ($viewId > 0) { $stmt = $pdo->prepare('SELECT * FROM autocontrollo_temperature_results WHERE inspection_id=? ORDER BY sort_order'); $stmt->execute([$viewId]); $viewResults = $stmt->fetchAll(PDO::FETCH_ASSOC); }
$title = 'Autocontrollo Temperature'; include __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-4"><div><div class="text-muted small text-uppercase fw-semibold">Autocontrollo</div><h1 class="h4 mb-0"><i class="bi bi-thermometer-half me-1"></i>Temperature</h1></div><a class="btn btn-outline-danger" href="<?= e($base) ?>/reports/autocontrollo_temperature_pdf.php?season=1"><i class="bi bi-file-earmark-pdf me-1"></i>Esporta PDF stagione</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Procedura completata e rapporto inviato agli utenti Amministrazione.</div><?php endif; ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>La temperatura di un <strong>congelatore o di una cella</strong> deve rispettare la temperatura di esercizio + 3 °C (D.Lgs. 110/92); per i <strong>frigoriferi</strong> è ammessa una tolleranza temporanea di +1–2 °C.</div>

<?php if ($activeInspection && $currentResult): ?>
<div class="card shadow-sm border-primary mx-auto" style="max-width:720px"><div class="card-body p-3 p-md-4">
  <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3"><div><span class="badge text-bg-primary"><?= e(ucfirst($activeInspection['time_slot'])) ?></span><h2 class="h5 mt-2 mb-0">Verifica <?= count($activeResults) ?> frigoriferi</h2></div><button class="btn btn-success" type="button" id="temperatureAllCompliant"><i class="bi bi-check2-all me-1"></i>Imposta tutti su Sì</button></div>
  <p class="text-muted small">Usa il pulsante per confermare rapidamente tutti i frigoriferi, poi imposta su <strong>No</strong> soltanto quelli difformi prima di salvare.</p>
  <form method="post" id="temperatureInspectionForm"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_all"><input type="hidden" name="inspection_id" value="<?= (int)$activeInspection['id'] ?>">
  <div class="table-responsive mb-4"><table class="table align-middle mb-0"><thead><tr><th>ID Frigo</th><th class="text-center">Mattina</th><th class="text-center">Pomeriggio</th></tr></thead><tbody><?php foreach ($activeResults as $result): $resultId = (int)$result['id']; ?><tr><td><strong><?= e($result['refrigerator_label']) ?></strong><div class="text-muted small"><?= e(ucfirst($result['appliance_type'])) ?> · <?= e($result['operating_temperature']) ?> °C</div></td><?php foreach (['mattina','pomeriggio'] as $slot): ?><td class="text-center"><?php if ($activeInspection['time_slot'] === $slot): ?><div class="btn-group" role="group" aria-label="Conformità <?= e($result['refrigerator_label']) ?>"><input class="btn-check temperature-compliance-yes" type="radio" name="compliance[<?= $resultId ?>]" id="temperatureYes<?= $resultId ?>" value="1" required><label class="btn btn-outline-success" for="temperatureYes<?= $resultId ?>">Sì</label><input class="btn-check" type="radio" name="compliance[<?= $resultId ?>]" id="temperatureNo<?= $resultId ?>" value="0" required><label class="btn btn-outline-danger" for="temperatureNo<?= $resultId ?>">No</label></div><?php else: ?>—<?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
    <button class="btn btn-primary btn-lg w-100">Salva e completa la procedura <i class="bi bi-check-circle ms-1"></i></button>
  </form>
</div></div>
<?php else: ?>
<div class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5">Prossimo controllo in ordine cronologico</h2>
<?php if (empty($range['start']) || empty($range['end'])): ?><div class="alert alert-warning mb-0">Configurare le date di apertura e chiusura della stagione.</div>
<?php elseif ($nextDue === null): ?><div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i>Tutti i controlli previsti dalla data di apertura alla data di chiusura sono stati completati.</div>
<?php else: $available = autocontrollo_temperature_is_available($nextDue['date'], $nextDue['slot']); $overdue = $nextDue['date'] < $todayValue; ?><div class="border rounded p-3"><div class="d-flex flex-wrap align-items-center gap-2 mb-3"><span class="fs-5 fw-semibold"><?= e((new DateTimeImmutable($nextDue['date']))->format('d/m/Y')) ?></span><span class="badge text-bg-primary"><?= e(ucfirst($nextDue['slot'])) ?></span><?php if ($overdue): ?><span class="badge text-bg-warning">Arretrato</span><?php endif; ?></div><p class="text-muted"><?= $nextDue['date'] === $todayValue && $nextDue['slot'] === 'pomeriggio' && !$available ? 'Il controllo pomeridiano sarà disponibile dalle ore 12:00.' : 'I controlli successivi resteranno bloccati finché questa procedura non sarà completata.' ?></p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="inspection_date" value="<?= e($nextDue['date']) ?>"><input type="hidden" name="time_slot" value="<?= e($nextDue['slot']) ?>"><button class="btn btn-primary" <?= $available ? '' : 'disabled' ?>><i class="bi bi-play-circle me-1"></i><?= $available ? 'Avvia controllo' : 'Non ancora disponibile' ?></button></form></div><?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Rilevazioni effettuate</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data e ora</th><th>Fascia</th><th>Operatore</th><th>ID Frigo</th><th>Mattina</th><th>Pomeriggio</th><th>Anomalie da risolvere</th><th></th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="8" class="text-center text-muted py-4">Nessuna procedura avviata.</td></tr><?php endif; ?>
<?php foreach ($inspections as $inspection): $anomalies = (int)$inspection['anomaly_count']; ?><tr><td><?= e((new DateTimeImmutable($inspection['started_at']))->format('d/m/Y H:i')) ?></td><td><?= e(ucfirst($inspection['time_slot'])) ?></td><td><?= e($inspection['operator_name'] ?: ($inspection['operator_email'] ?? '—')) ?></td><td><?= (int)$inspection['refrigerator_count'] ?> frigoriferi</td><td><?= $inspection['time_slot'] === 'mattina' ? ($inspection['status'] === 'completata' ? 'Sì' : 'In corso') : '—' ?></td><td><?= $inspection['time_slot'] === 'pomeriggio' ? ($inspection['status'] === 'completata' ? 'Sì' : 'In corso') : '—' ?></td><td><?php if ($anomalies): ?><span class="badge text-bg-danger"><?= $anomalies ?> <?= $anomalies === 1 ? 'anomalia' : 'anomalie' ?></span><?php else: ?><span class="badge text-bg-success">Nessuna</span><?php endif; ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-danger" href="<?= e($base) ?>/reports/autocontrollo_temperature_pdf.php?id=<?= (int)$inspection['id'] ?>" title="Esporta procedura in PDF" aria-label="Esporta controllo temperature #<?= (int)$inspection['id'] ?> in PDF"><i class="bi bi-file-earmark-pdf"></i></a> <a class="btn btn-sm btn-outline-secondary" href="?view=<?= (int)$inspection['id'] ?>#dettaglio">Dettagli</a><?php if ($inspection['status'] === 'in_corso'): ?> <a class="btn btn-sm btn-primary" href="?inspection=<?= (int)$inspection['id'] ?>">Continua</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($viewResults): ?><div class="card shadow-sm mt-3" id="dettaglio"><div class="card-header bg-white"><h2 class="h5 mb-0">Dettaglio procedura #<?= $viewId ?></h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>ID Frigo</th><th>Tipologia</th><th>Temperatura esercizio</th><th>Conforme</th><th>Verificata</th></tr></thead><tbody><?php foreach ($viewResults as $result): ?><tr class="<?= $result['is_compliant'] !== null && !(int)$result['is_compliant'] ? 'table-danger' : '' ?>"><td><?= e($result['refrigerator_label']) ?></td><td><?= e(ucfirst($result['appliance_type'])) ?></td><td><?= e($result['operating_temperature']) ?> °C</td><td><?= $result['is_compliant'] === null ? '—' : ((int)$result['is_compliant'] ? 'Sì' : '<strong>NO — Anomalia</strong>') ?></td><td><?= $result['checked_at'] ? e((new DateTimeImmutable($result['checked_at']))->format('d/m/Y H:i')) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<script src="<?= e($base) ?>/assets/autocontrollo-temperature.js" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
