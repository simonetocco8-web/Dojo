<?php

$page = file_get_contents(__DIR__ . '/../autocontrollo_temperature.php');
$core = file_get_contents(__DIR__ . '/../core/autocontrollo_temperature.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
if (in_array(false, [$page, $core, $database, $settings, $header], true)) throw new RuntimeException('Impossibile leggere i file Autocontrollo Temperature.');

$requirements = [
  [$database, 'ensure_autocontrollo_temperature_tables', 'tabelle rilevazioni'],
  [$database, "ENUM('mattina','pomeriggio')", 'due controlli giornalieri'],
  [$settings, 'refrigerator_create', 'configurazione frigoriferi'],
  [$core, 'autocontrollo_temperature_start', 'avvio guidato'],
  [$core, 'autocontrollo_temperature_send_report', 'rapporto email'],
  [$page, 'D.Lgs. 110/92', 'disclaimer temperature'],
  [$page, 'ID Frigo', 'tabella giornaliera'],
  [$page, 'resolve_anomaly', 'risoluzione anomalie'],
  [$header, '/autocontrollo_temperature.php', 'voce menu'],
];
foreach ($requirements as [$source, $needle, $label]) if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
if (substr_count($header, '/autocontrollo_temperature.php') !== 2) throw new RuntimeException('La voce Temperature deve essere presente nei menu desktop e mobile.');

echo "Procedura Autocontrollo Temperature verificata.\n";
