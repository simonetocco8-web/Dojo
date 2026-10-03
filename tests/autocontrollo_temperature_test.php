<?php
$page = file_get_contents(__DIR__ . '/../autocontrollo_temperature.php');
$core = file_get_contents(__DIR__ . '/../core/autocontrollo_temperature.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
$script = file_get_contents(__DIR__ . '/../assets/autocontrollo-temperature.js');
$backfill = file_get_contents(__DIR__ . '/../database/backfill_autocontrollo_temperature.php');
foreach ([
  [$page, 'D.Lgs. 110/92', 'disclaimer normativo'], [$page, '+1–2 °C', 'tolleranza frigoriferi'],
  [$core, "['mattina', 'pomeriggio']", 'due controlli giornalieri'],
  [$page, 'Imposta tutti su Sì', 'compilazione rapida conforme'], [$page, 'compliance[', 'correzione singole difformità'], [$page, 'Anomalie da risolvere', 'riepilogo anomalie'],
  [$page, '<th>ID Frigo</th><th class="text-center">Mattina</th><th class="text-center">Pomeriggio</th>', 'tabella controlli giornalieri'],
  [$core, 'autocontrollo_temperature_start', 'avvio procedura'], [$core, 'autocontrollo_temperature_send_report', 'email amministrazione'],
  [$core, 'autocontrollo_temperature_next_due', 'ordine cronologico obbligatorio'], [$page, 'inspection_date', 'avvio controllo arretrato'],
  [$core, 'autocontrollo_temperature_backfill_compliant', 'riallineamento storico conforme'], [$backfill, '--confirm', 'comando di riallineamento esplicito'],
  [$database, 'ensure_autocontrollo_temperature_inspections_tables', 'tabelle temperature'],
  [$header, '/autocontrollo_temperature.php', 'link menu'],
  [$script, '.temperature-compliance-yes', 'selezione di tutti i valori conformi'],
] as [$source, $needle, $label]) if ($source === false || !str_contains($source, $needle)) throw new RuntimeException('Funzionalità mancante: ' . $label);

require_once __DIR__ . '/../core/autocontrollo_temperature.php';
$range = ['start' => '2026-05-01', 'end' => '2026-05-02'];
$schedule = autocontrollo_temperature_schedule($range);
if (count($schedule) !== 4 || $schedule[0] !== ['date' => '2026-05-01', 'slot' => 'mattina'] || $schedule[3] !== ['date' => '2026-05-02', 'slot' => 'pomeriggio']) throw new RuntimeException('Calendario giornaliero non valido.');
$next = autocontrollo_temperature_next_due($range, [['inspection_date' => '2026-05-01', 'time_slot' => 'mattina', 'status' => 'completata']]);
if ($next !== ['date' => '2026-05-01', 'slot' => 'pomeriggio']) throw new RuntimeException('Il controllo antecedente non viene rispettato.');
if (autocontrollo_temperature_is_available('2026-05-01', 'pomeriggio', new DateTimeImmutable('2026-05-01 11:59:59', new DateTimeZone('Europe/Rome')))) throw new RuntimeException('Il controllo pomeridiano è disponibile prima delle 12:00.');
if (!autocontrollo_temperature_is_available('2026-05-01', 'pomeriggio', new DateTimeImmutable('2026-05-01 12:00:00', new DateTimeZone('Europe/Rome')))) throw new RuntimeException('Il controllo pomeridiano non è disponibile dalle 12:00.');
echo "Autocontrollo temperature verificato.\n";
