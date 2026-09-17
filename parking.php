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
  'P1'=>[95,105],'P2'=>[95,175],'P3'=>[95,245],'P4'=>[95,315],'P5'=>[95,385],
  'P6'=>[250,115],'P7'=>[250,190],'P8'=>[250,265],'P9'=>[250,340],'P10'=>[250,415],
  'P11'=>[405,115],'P12'=>[405,190],'P13'=>[405,265],'P14'=>[405,340],'P15'=>[405,415],
  'P16'=>[560,115],'P17'=>[560,190],'P18'=>[560,265],'P19'=>[560,340],'P20'=>[560,415],
  'P21'=>[715,115],'P22'=>[715,190],'P23'=>[715,265],'P24'=>[715,340],'P25'=>[715,415],
  'P26'=>[420,535],'P27'=>[575,535],
  'S1'=>[95,690],'S2'=>[165,690],'S3'=>[235,690],'S4'=>[305,690],'S5'=>[375,690],'S6'=>[445,690],
  'S7'=>[515,690],'S8'=>[585,690],'S9'=>[655,690],'S10'=>[725,690],'S11'=>[795,690],
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
      <svg class="parking-map" viewBox="0 0 900 780" role="img" aria-labelledby="parkingMapTitle parkingMapDescription">
        <title id="parkingMapTitle">Mappa interattiva dei parcheggi</title><desc id="parkingMapDescription">Ventisette posti nel parcheggio primario e undici nel secondario. Seleziona un posto per modificarlo.</desc>
        <defs><pattern id="asphalt" width="20" height="20" patternUnits="userSpaceOnUse"><rect width="20" height="20" fill="#26323a"/><circle cx="3" cy="5" r="1" fill="#34434c"/><circle cx="14" cy="15" r="1" fill="#1d282f"/></pattern></defs>
        <rect x="25" y="35" width="850" height="580" rx="35" fill="url(#asphalt)" stroke="#dce6ea" stroke-width="10"/>
        <rect x="25" y="635" width="850" height="115" rx="25" fill="url(#asphalt)" stroke="#dce6ea" stroke-width="10"/>
        <path d="M40 625 H860" stroke="#7cc34b" stroke-width="14"/><text x="55" y="70" class="map-zone-title">PARCHEGGIO PRIMARIO</text><text x="55" y="665" class="map-zone-title">SECONDARIO</text>
        <g class="parking-lines" aria-hidden="true">
          <?php foreach ([130,285,440,595,750] as $x): ?><path d="M<?= $x ?> 90v350"/><?php endforeach; ?>
          <?php foreach ([145,220,295,370,445] as $y): ?><path d="M55 <?= $y ?>h720"/><?php endforeach; ?>
          <?php foreach ([130,200,270,340,410,480,550,620,690,760,830] as $x): ?><path d="M<?= $x ?> 675v65"/><?php endforeach; ?>
        </g>
        <rect x="300" y="475" width="90" height="105" rx="8" fill="#edf1f2"/><path d="M315 490h60v60h-60z" fill="#fff"/><text x="345" y="568" text-anchor="middle" class="building-label">INGRESSO</text>
        <?php foreach ($positions as $id => [$x, $y]): $space = $spacesById[$id]; $assignment = $assignmentLabels[$space['assignment_type']] ?? 'Nessuna'; if ($space['assignment_detail']) $assignment .= ': ' . $space['assignment_detail']; ?>
          <g class="parking-space status-<?= e($space['status']) ?>" tabindex="0" role="button" aria-label="<?= e($id . ', ' . $space['status'] . ', ' . $assignment) ?>" data-space='<?= e(json_encode($space, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
            <rect x="<?= $x - 29 ?>" y="<?= $y - 24 ?>" width="58" height="48" rx="10"/><text x="<?= $x ?>" y="<?= $y + 5 ?>" text-anchor="middle"><?= e($id) ?></text><title><?= e($id . ' · ' . ucfirst($space['status']) . ' · ' . $assignment) ?></title>
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
