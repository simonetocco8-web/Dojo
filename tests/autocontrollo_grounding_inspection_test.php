<?php

require_once __DIR__ . '/../core/autocontrollo_grounding.php';

$schedule = autocontrollo_grounding_schedule(['start' => '2026-06-01', 'end' => '2026-09-20']);
if (($schedule['pre_apertura']['date'] ?? '') !== '2026-05-18') {
    throw new RuntimeException('Il controllo pre-apertura deve essere previsto due settimane prima.');
}
if (($schedule['post_chiusura']['date'] ?? '') !== '2026-09-22') {
    throw new RuntimeException('Il controllo post-chiusura deve essere previsto due giorni dopo.');
}

$page = file_get_contents(__DIR__ . '/../autocontrollo_messa_a_terra.php');
$core = file_get_contents(__DIR__ . '/../core/autocontrollo_grounding.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
$requirements = [
    [$page, 'name="clamp_checked"', 'verifica morsetto'],
    [$page, 'name="antioxidant_applied"', 'spray disossidante'],
    [$page, 'Rilevazioni effettuate', 'tabella procedure'],
    [$core, 'autocontrollo_grounding_send_report', 'rapporto email'],
    [$database, 'ensure_autocontrollo_grounding_inspections_tables', 'tabelle controlli'],
    [$header, '/autocontrollo_messa_a_terra.php', 'voce menu'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if ($source === false || strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Procedura stagionale di messa a terra verificata.\n";
