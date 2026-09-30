<?php
$page = file_get_contents(__DIR__ . '/../autocontrollo_temperature.php');
$core = file_get_contents(__DIR__ . '/../core/autocontrollo_temperature.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
foreach ([
  [$page, 'D.Lgs. 110/92', 'disclaimer normativo'], [$page, '+1–2 °C', 'tolleranza frigoriferi'],
  [$page, "['mattina'=>'Mattina','pomeriggio'=>'Pomeriggio']", 'due controlli giornalieri'],
  [$page, 'La temperatura è conforme?', 'verifica guidata'], [$page, 'Anomalie da risolvere', 'riepilogo anomalie'],
  [$page, '<th>ID Frigo</th><th class="text-center">Mattina</th><th class="text-center">Pomeriggio</th>', 'tabella controlli giornalieri'],
  [$core, 'autocontrollo_temperature_start', 'avvio procedura'], [$core, 'autocontrollo_temperature_send_report', 'email amministrazione'],
  [$database, 'ensure_autocontrollo_temperature_inspections_tables', 'tabelle temperature'],
  [$header, '/autocontrollo_temperature.php', 'link menu'],
] as [$source, $needle, $label]) if ($source === false || !str_contains($source, $needle)) throw new RuntimeException('Funzionalità mancante: ' . $label);
echo "Autocontrollo temperature verificato.\n";
