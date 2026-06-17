<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';

start_session();
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
if (!user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  exit('Permesso negato.');
}

const RFI_RICADI_MONITOR_URL = 'https://iechub.rfi.it/ArriviPartenze/ArrivalsDepartures/Monitor?placeId=129&arrivals=False';

function trains_fetch_monitor_html(): ?string {
  $cacheFile = sys_get_temp_dir() . '/dojo_rfi_ricadi_departures.html';
  if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 120) {
    $cached = file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') return $cached;
  }

  $headers = [
    'User-Agent: Mozilla/5.0 (compatible; Dojo/1.0)',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: it-IT,it;q=0.9,en;q=0.8',
  ];

  $html = null;
  if (function_exists('curl_init')) {
    $ch = curl_init(RFI_RICADI_MONITOR_URL);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    if (is_string($response) && $response !== '') {
      $html = $response;
    }
    curl_close($ch);
  }

  if ($html === null) {
    $context = stream_context_create([
      'http' => [
        'timeout' => 15,
        'header' => implode("\r\n", $headers),
      ],
    ]);
    $response = @file_get_contents(RFI_RICADI_MONITOR_URL, false, $context);
    if (is_string($response) && $response !== '') {
      $html = $response;
    }
  }

  if ($html !== null) {
    @file_put_contents($cacheFile, $html);
  }

  return $html;
}

function trains_clean_text(string $value): string {
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
  return trim($value);
}

function trains_cell_text(DOMXPath $xpath, DOMElement $row, string $id): string {
  $node = $xpath->query('.//td[@id="' . $id . '"]', $row)->item(0);
  if (!$node) return '';
  $label = $node instanceof DOMElement ? (string)$node->getAttribute('aria-label') : '';
  $text = trains_clean_text($node->textContent ?? '');
  if ($text === '' && $label !== '' && !in_array($label, ['Nessuno', 'No', 'NonDisponibile'], true)) {
    return trains_clean_text($label);
  }
  return $text;
}

function trains_image_alt(DOMXPath $xpath, DOMElement $row, string $cellId): string {
  $node = $xpath->query('.//td[@id="' . $cellId . '"]//img[@alt]', $row)->item(0);
  return $node instanceof DOMElement ? trains_clean_text($node->getAttribute('alt')) : '';
}

function trains_next_stops(DOMXPath $xpath, DOMElement $row): string {
  $node = $xpath->query('.//td[@id="RDettagli"]//*[contains(concat(" ", normalize-space(@class), " "), " testoinfoaggiuntive ")]', $row)->item(0);
  return $node ? trains_clean_text($node->textContent ?? '') : '';
}

function trains_parse_departures(string $html): array {
  if (!class_exists('DOMDocument')) return [];

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
  libxml_clear_errors();
  if (!$loaded) return [];

  $xpath = new DOMXPath($dom);
  $rows = [];
  foreach ($xpath->query('//tbody[@id="bodyTabId"]/tr[@name="treno"]') as $row) {
    if (!$row instanceof DOMElement) continue;
    $train = trains_cell_text($xpath, $row, 'RTreno');
    if ($train === '') continue;

    $delay = trains_cell_text($xpath, $row, 'RRitardo');
    $platform = trains_cell_text($xpath, $row, 'RBinario');
    $departing = trains_cell_text($xpath, $row, 'RExLampeggio');

    $rows[] = [
      'operator' => trains_image_alt($xpath, $row, 'RVettore'),
      'category' => trains_image_alt($xpath, $row, 'RCategoria'),
      'train' => $train,
      'destination' => trains_cell_text($xpath, $row, 'RStazione'),
      'time' => trains_cell_text($xpath, $row, 'ROrario'),
      'delay' => $delay !== '' ? $delay : '—',
      'platform' => $platform !== '' ? $platform : '—',
      'departing' => $departing !== '' ? $departing : '—',
      'next_stops' => trains_next_stops($xpath, $row),
    ];
  }

  return $rows;
}

$monitorHtml = trains_fetch_monitor_html();
$departures = $monitorHtml !== null ? trains_parse_departures($monitorHtml) : [];
$lastUpdated = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));

$title = 'Treni da Ricadi Capo Vaticano';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-0">Treni da Ricadi Capo Vaticano</h1>
    <div class="text-muted small">Partenze dalla stazione di Ricadi-Capo Vaticano · aggiornato alle <?= e($lastUpdated->format('H:i')) ?></div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(RFI_RICADI_MONITOR_URL) ?>" target="_blank" rel="noopener">Apri fonte RFI</a>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($departures): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Ora</th>
              <th>Treno</th>
              <th>Categoria</th>
              <th>Destinazione</th>
              <th>Ritardo</th>
              <th>Binario</th>
              <th>In partenza</th>
              <th>Informazioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($departures as $index => $departure): ?>
              <?php $modalId = 'trainStops_' . $index; ?>
              <tr>
                <td class="fw-semibold"><?= e($departure['time']) ?></td>
                <td><?= e($departure['train']) ?></td>
                <td><?= e($departure['category'] ?: $departure['operator']) ?></td>
                <td><?= e($departure['destination']) ?></td>
                <td><?= e($departure['delay']) ?></td>
                <td><?= e($departure['platform']) ?></td>
                <td><?= e($departure['departing']) ?></td>
                <td>
                  <?php if ($departure['next_stops'] !== ''): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>">
                      Fermate
                    </button>
                    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
                      <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                          <div class="modal-header">
                            <h5 class="modal-title" id="<?= e($modalId) ?>Label">Fermate successive · Treno <?= e($departure['train']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                          </div>
                          <div class="modal-body"><?= e($departure['next_stops']) ?></div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="alert alert-warning mb-0">
        Non è stato possibile recuperare in questo momento i dati dei treni da RFI.
        <a href="<?= e(RFI_RICADI_MONITOR_URL) ?>" target="_blank" rel="noopener">Apri la pagina originale</a>.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
