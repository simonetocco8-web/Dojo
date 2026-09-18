<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) {
  header('Location: ' . $base . '/index.php?msg=auth');
  exit;
}
if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  echo 'Accesso negato';
  exit;
}

$pdo = db();
ensure_parking_spaces_table($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $spaceId = strtoupper(trim((string)($_POST['space_id'] ?? '')));
  $size = (string)($_POST['size'] ?? '');
  $status = (string)($_POST['status'] ?? '');
  $assignmentType = (string)($_POST['assignment_type'] ?? '');
  $assignmentDetail = trim((string)($_POST['assignment_detail'] ?? ''));
  $validSizes = ['piccolo', 'medio', 'grande'];
  $validStatuses = ['libero', 'occupato', 'riservato'];
  $validAssignments = ['nessuna', 'appartamento', 'personale', 'tramontoday', 'sunset_beach_bar', 'altro'];

  if (!csrf_check($_POST['csrf_token'] ?? '')) $errors[] = 'Sessione scaduta: ricarica la pagina e riprova.';
  if (!preg_match('/^(P(?:[1-9]|1[0-9]|2[0-7])|S(?:[1-9]|1[01]))$/', $spaceId)) $errors[] = 'Posto auto non valido.';
  if (!in_array($size, $validSizes, true)) $errors[] = 'Dimensione non valida.';
  if (!in_array($status, $validStatuses, true)) $errors[] = 'Stato non valido.';
  if (!in_array($assignmentType, $validAssignments, true)) $errors[] = 'Assegnazione non valida.';
  if ($assignmentType === 'appartamento' && ($assignmentDetail === '' || !ctype_digit($assignmentDetail))) $errors[] = 'Inserisci il numero dell’appartamento.';
  if (in_array($assignmentType, ['personale', 'altro'], true) && $assignmentDetail === '') $errors[] = 'Specifica a chi è assegnato il posto.';
  if (mb_strlen($assignmentDetail) > 190) $errors[] = 'Il dettaglio assegnazione è troppo lungo.';

  if (!$errors) {
    if ($assignmentType === 'nessuna') $assignmentDetail = '';
    $stmt = $pdo->prepare('UPDATE parking_spaces SET size=?, is_covered=?, status=?, assignment_type=?, assignment_detail=?, updated_by=? WHERE space_id=?');
    $stmt->execute([$size, isset($_POST['is_covered']) ? 1 : 0, $status, $assignmentType, $assignmentDetail ?: null, $user['id'], $spaceId]);
    $_SESSION['parking_flash'] = 'Posto ' . $spaceId . ' aggiornato correttamente.';
    header('Location: ' . $base . '/parking.php');
    exit;
  }
}

$spaces = $pdo->query('SELECT * FROM parking_spaces ORDER BY parking, CAST(SUBSTRING(space_id, 2) AS UNSIGNED)')->fetchAll();
$spacesById = [];
$freeCounts = ['primario' => 0, 'secondario' => 0];
foreach ($spaces as $space) {
  $spacesById[$space['space_id']] = $space;
  if ($space['status'] === 'libero') $freeCounts[$space['parking']]++;
}
$flash = $_SESSION['parking_flash'] ?? '';
unset($_SESSION['parking_flash']);
$assignmentLabels = [
  'nessuna' => 'Nessuna', 'appartamento' => 'Appartamento', 'personale' => 'Personale',
  'tramontoday' => 'TramontoDay', 'sunset_beach_bar' => 'SunSet Beach Bar', 'altro' => 'Altro',
];
$positions = [
  'P1'=>[265,145],'P2'=>[265,215],'P3'=>[265,285],'P4'=>[265,355],'P5'=>[265,425],
  'P6'=>[575,155],'P7'=>[575,230],'P8'=>[575,305],'P9'=>[575,380],'P10'=>[575,455],'P11'=>[575,525],'P12'=>[575,590],
  'P13'=>[730,675],'P14'=>[730,750],'P15'=>[730,825],'P16'=>[730,900],
  'P17'=>[75,365],'P18'=>[92,470],'P19'=>[98,590],'P20'=>[250,585],'P21'=>[270,660],'P22'=>[245,735],
  'P23'=>[105,815],'P24'=>[155,920],'P25'=>[235,935],'P26'=>[315,950],'P27'=>[395,975],
  'S1'=>[895,135],'S2'=>[895,210],'S3'=>[895,285],'S4'=>[895,360],'S5'=>[895,435],'S6'=>[895,510],
  'S7'=>[895,585],'S8'=>[895,660],'S9'=>[895,735],'S10'=>[895,810],'S11'=>[895,885],
];
$title = 'Parcheggi';
include __DIR__ . '/partials/header.php';
?>
<div class="container-fluid parking-page">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h1 class="h3 mb-1">Parcheggi</h1><p class="text-muted mb-0">Seleziona un posto sulla mappa per gestirlo.</p></div>
    <div class="parking-legend" aria-label="Legenda stati">
      <span><i class="parking-dot status-libero"></i> Libero</span><span><i class="parking-dot status-occupato"></i> Occupato</span><span><i class="parking-dot status-riservato"></i> Riservato</span>
    </div>
  </div>
  <?php if ($flash): ?><div class="alert alert-success" role="status"><?= e($flash) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
  <div class="row g-3 mb-4">
    <?php foreach ([['primario','Primario',27,'bi-p-square'], ['secondario','Secondario',11,'bi-signpost-split']] as $summary): ?>
      <div class="col-12 col-sm-6"><div class="card parking-summary-card h-100"><div class="card-body d-flex align-items-center gap-3"><span class="parking-summary-icon"><i class="bi <?= e($summary[3]) ?>"></i></span><div><div class="text-muted small text-uppercase fw-semibold">Parcheggio <?= e($summary[1]) ?></div><div><strong class="display-6"><?= (int)$freeCounts[$summary[0]] ?></strong> <span class="text-muted">posti liberi su <?= $summary[2] ?></span></div></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="card shadow-sm border-0"><div class="card-body p-2 p-md-4">
    <div class="parking-map-scroll">
      <svg class="parking-map" viewBox="0 0 1000 1100" role="img" aria-labelledby="parkingMapTitle parkingMapDescription">
        <title id="parkingMapTitle">Mappa interattiva dei parcheggi</title><desc id="parkingMapDescription">Ventisette posti nel parcheggio primario e undici nel secondario. Seleziona un posto per modificarlo.</desc>
        <defs>
          <pattern id="asphalt" width="18" height="18" patternUnits="userSpaceOnUse"><rect width="18" height="18" fill="#30383d"/><circle cx="3" cy="5" r=".8" fill="#475158"/><circle cx="13" cy="14" r=".8" fill="#20292e"/></pattern>
          <pattern id="lawn" width="22" height="22" patternUnits="userSpaceOnUse"><rect width="22" height="22" fill="#58b927"/><path d="M2 20L8 5M13 21l6-14" stroke="#65c936" stroke-width="2" opacity=".55"/></pattern>
          <filter id="mapShadow"><feDropShadow dx="3" dy="5" stdDeviation="4" flood-opacity=".35"/></filter>
          <g id="tree"><circle r="28" fill="#173f13" opacity=".35"/><circle cy="-4" r="23" fill="#277c19" stroke="#123b0e" stroke-width="3"/><circle cx="-10" cy="-8" r="12" fill="#62bd24"/><circle cx="9" cy="-7" r="13" fill="#55aa1d"/><circle cy="8" r="13" fill="#3f9718"/></g>
        </defs>
        <rect width="1000" height="1100" fill="#3e464b"/>
        <path d="M170 55L780 85V45H970V925Q970 970 925 970H790V1040H420L285 955H45L25 315L175 280Z" fill="url(#lawn)" stroke="#10181b" stroke-width="8"/>
        <path d="M190 95L670 120V610H430Q420 760 350 820L420 875V1010L285 925H55L48 330L185 295Z" fill="url(#asphalt)" stroke="#f2f0e9" stroke-width="9" filter="url(#mapShadow)"/>
        <path d="M790 95H940V875Q940 925 890 925H790Z" fill="url(#asphalt)" stroke="#f2f0e9" stroke-width="9" filter="url(#mapShadow)"/>
        <path d="M670 95H790V630H670Z" fill="url(#lawn)" stroke="#f2f0e9" stroke-width="8"/>
        <path d="M500 620H790V925H500Q540 865 520 790Z" fill="url(#asphalt)" stroke="#f2f0e9" stroke-width="9"/>
        <path d="M185 92L670 118V610H430M790 95H940V875Q940 925 890 925H790" fill="none" stroke="#0b94ff" stroke-width="8" stroke-linejoin="round"/>
        <g class="parking-lines" aria-hidden="true">
          <?php foreach ([180,250,320,390,460] as $y): ?><path d="M195 <?= $y ?>h155"/><path d="M500 <?= $y + 10 ?>h165"/><?php endforeach; ?>
          <path d="M500 540h165M500 605h165M500 620v105"/>
          <?php foreach ([175,250,325,400,475,550,625,700,775,850] as $y): ?><path d="M840 <?= $y ?>h100"/><?php endforeach; ?>
          <?php foreach ([[55,410,105,345],[55,530,110,450],[55,690,125,600],[65,875,140,780],[145,925,190,815],[225,930,260,825],[305,950,335,840],[385,980,410,875],[665,650,790,650],[665,725,790,725],[665,800,790,800],[665,875,790,875]] as $line): ?><path d="M<?= $line[0] ?> <?= $line[1] ?>L<?= $line[2] ?> <?= $line[3] ?>"/><?php endforeach; ?>
        </g>
        <path d="M335 300h160v315H335l-125-90V455q45 55 125 35Z" fill="#faf9f5" stroke="#111" stroke-width="4" filter="url(#mapShadow)"/>
        <path d="M335 300h160v315H335" fill="#f4f2ed"/><path d="M410 390v85q0 45 42 45h43" fill="none" stroke="#111" stroke-width="4"/>
        <text x="410" y="575" text-anchor="middle" class="building-label">EDIFICIO</text>
        <rect x="515" y="935" width="250" height="165" fill="#f8f7f3" stroke="#222" stroke-width="4"/><rect x="70" y="990" width="225" height="110" fill="#f8f7f3" stroke="#222" stroke-width="4"/>
        <g aria-hidden="true"><use href="#tree" x="735" y="175"/><use href="#tree" x="735" y="320"/><use href="#tree" x="735" y="470"/><use href="#tree" x="285" y="585"/><use href="#tree" x="235" y="780"/><use href="#tree" x="135" y="1015"/><use href="#tree" x="220" y="1020"/></g>
        <text x="205" y="120" class="map-zone-title">PARCHEGGIO PRIMARIO</text><text x="815" y="120" class="map-zone-title">SECONDARIO</text>
        <?php foreach ($positions as $id => [$x, $y]): $space = $spacesById[$id]; $assignment = $assignmentLabels[$space['assignment_type']] ?? 'Nessuna'; if ($space['assignment_detail']) $assignment .= ': ' . $space['assignment_detail']; ?>
          <g class="parking-space status-<?= e($space['status']) ?>" tabindex="0" role="button" aria-label="<?= e($id . ', ' . $space['status'] . ', ' . $assignment) ?>" data-space='<?= e(json_encode($space, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
            <circle cx="<?= $x ?>" cy="<?= $y ?>" r="24"/><text x="<?= $x ?>" y="<?= $y + 5 ?>" text-anchor="middle"><?= e($id) ?></text><title><?= e($id . ' · ' . ucfirst($space['status']) . ' · ' . $assignment) ?></title>
          </g>
        <?php endforeach; ?>
      </svg>
    </div>
    <p class="text-center text-muted small mt-3 mb-0"><i class="bi bi-hand-index-thumb me-1"></i>Tocca un posto per visualizzare assegnazione e caratteristiche.</p>
  </div></div>
</div>

<div class="modal fade" id="parkingSpaceModal" tabindex="-1" aria-labelledby="parkingSpaceModalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post"><div class="modal-header"><div><div class="text-muted small">Gestione posto auto</div><h2 class="modal-title h4" id="parkingSpaceModalTitle">Posto</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div>
  <div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="space_id" id="parkingSpaceId">
    <div class="row g-3"><div class="col-6"><label class="form-label" for="parkingStatus">Stato</label><select class="form-select" name="status" id="parkingStatus" required><option value="libero">Libero</option><option value="occupato">Occupato</option><option value="riservato">Riservato</option></select></div>
    <div class="col-6"><label class="form-label" for="parkingSize">Dimensione</label><select class="form-select" name="size" id="parkingSize" required><option value="piccolo">Piccolo</option><option value="medio">Medio</option><option value="grande">Grande</option></select></div>
    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_covered" value="1" id="parkingCovered"><label class="form-check-label" for="parkingCovered">Posto coperto</label></div></div>
    <div class="col-12"><label class="form-label" for="parkingAssignmentType">Assegnazione</label><select class="form-select" name="assignment_type" id="parkingAssignmentType" required><?php foreach ($assignmentLabels as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-12" id="parkingAssignmentDetailWrap"><label class="form-label" id="parkingAssignmentDetailLabel" for="parkingAssignmentDetail">Dettaglio</label><input class="form-control" name="assignment_detail" id="parkingAssignmentDetail" maxlength="190"><div class="form-text" id="parkingAssignmentHelp"></div></div></div>
  </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salva modifiche</button></div></form>
</div></div></div>
<?php $pageScripts = ['assets/parking.js']; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
