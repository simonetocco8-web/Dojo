<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--confirm', $argv, true)) {
  fwrite(STDERR, "Uso: php database/backfill_autocontrollo_temperature.php --confirm\n");
  fwrite(STDERR, "Sovrascrive con Sì tutti i controlli temperature dall'apertura fino a oggi.\n");
  exit(2);
}

require_once __DIR__ . '/../core/settings.php';
require_once __DIR__ . '/../core/autocontrollo_temperature.php';

$pdo = db();
ensure_autocontrollo_temperature_inspections_tables($pdo);
$range = get_summer_season_range($pdo);
$result = autocontrollo_temperature_backfill_compliant($pdo, $range);
printf("Completato: %d procedure e %d rilevazioni impostate su Sì.\n", $result['inspections'], $result['results']);
