<?php
$ecowittMetrics = [
  ['key' => 'temperature', 'label' => 'Temperatura', 'icon' => 'thermometer-sun', 'unit' => '°C'],
  ['key' => 'humidity', 'label' => 'Umidità', 'icon' => 'droplet-half', 'unit' => '%'],
  ['key' => 'wind_gust', 'label' => 'Raffica', 'icon' => 'wind', 'unit' => 'km/h'],
  ['key' => 'daily_rain', 'label' => 'Pioggia oggi', 'icon' => 'cloud-rain', 'unit' => 'mm'],
  ['key' => 'pressure', 'label' => 'Pressione', 'icon' => 'speedometer', 'unit' => 'hPa'],
];
$lowPressureAlert = $ecowittWeather['pressure'] !== null && $ecowittWeather['pressure'] <= 1013;
$windGustAlert = $ecowittWeather['wind_gust'] !== null && $ecowittWeather['wind_gust'] >= 40;
?>
<?php if ($ecowittWeather['configured'] || $boilerTemperatures['configured']): ?>
<div class="row g-3 mt-4 mb-4 align-items-stretch">
  <?php if ($ecowittWeather['configured']): ?>
    <?php foreach ($ecowittMetrics as $metric): ?>
      <?php $metricValue = $ecowittWeather[$metric['key']] ?? null; ?>
      <div class="col-6 col-md-4 col-xl">
        <div class="card shadow-sm h-100 border-top border-3 <?= $metricValue === null ? 'border-secondary' : 'border-info' ?>">
          <div class="card-body p-3">
            <div class="small text-muted text-uppercase fw-semibold"><i class="bi bi-<?= e($metric['icon']) ?> me-1"></i><?= e($metric['label']) ?></div>
            <div class="h3 mb-0 mt-2 text-nowrap"><?= $metricValue === null ? '<span class="text-muted">—</span>' : e(number_format((float)$metricValue, 1, ',', '')) . ' <small class="fs-6">' . e($metric['unit']) . '</small>' ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($boilerTemperatures['configured']): ?>
    <?php foreach ($boilerTemperatures['boilers'] as $boilerName => $temperature): ?>
      <div class="col-6 col-md-4 col-xl">
        <div class="card shadow-sm h-100 border-top border-3 <?= $temperature === null ? 'border-secondary' : 'border-danger' ?>">
          <div class="card-body p-3">
            <div class="small text-muted text-uppercase fw-semibold"><i class="bi bi-thermometer-half me-1"></i><?= e($boilerName) ?></div>
            <div class="h3 mb-0 mt-2 text-nowrap"><?= $temperature === null ? '<span class="text-muted">—</span>' : e(number_format((float)$temperature, 1, ',', '')) . ' <small class="fs-6">°C</small>' ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($lowPressureAlert): ?>
    <div class="col-12"><div class="alert alert-warning mb-0"><i class="bi bi-cloud-lightning-rain-fill me-2"></i><strong>Possibile maltempo in arrivo:</strong> pressione atmosferica pari o inferiore a 1013 hPa.</div></div>
  <?php endif; ?>
  <?php if ($windGustAlert): ?>
    <div class="col-12"><div class="alert alert-danger mb-0"><i class="bi bi-wind me-2"></i><strong>Possibili raffiche di vento:</strong> controllare la situazione degli ombrelloni in spiaggia.</div></div>
  <?php endif; ?>
  <?php if ($ecowittWeather['error']): ?>
    <div class="col-12"><div class="alert alert-warning mb-0">Dati Ecowitt temporaneamente non disponibili.</div></div>
  <?php endif; ?>
  <?php if ($boilerTemperatures['error']): ?>
    <div class="col-12"><div class="alert alert-warning mb-0">Temperature eWeLink temporaneamente non disponibili. <?php if ($is_admin && !$ewelinkDebug): ?><a class="alert-link" href="?ewelink_debug=1">Avvia debug MCP</a><?php endif; ?></div></div>
  <?php endif; ?>
  <?php if ($is_admin && $ewelinkDebug): ?>
    <div class="col-12">
      <div class="card border-warning shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center"><strong>Debug comunicazione eWeLink MCP</strong><a href="<?= e($base) ?>/dashboard.php" class="btn btn-sm btn-outline-secondary">Chiudi debug</a></div>
        <div class="card-body">
          <div class="alert <?= $boilerTemperatures['error'] ? 'alert-danger' : 'alert-success' ?> py-2"><?= e($boilerTemperatures['error'] ?: 'Comunicazione completata senza errori.') ?></div>
          <ol class="small font-monospace mb-0">
            <?php foreach (($boilerTemperatures['trace'] ?? []) as $trace): ?>
              <li class="mb-2"><strong><?= e($trace['time'] ?? '') ?> [<?= e($trace['step'] ?? '') ?>]</strong> <?= e($trace['message'] ?? '') ?><?php if (!empty($trace['context'])): ?><pre class="bg-light border rounded p-2 mt-1 mb-0 text-wrap"><?= e(json_encode($trace['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
