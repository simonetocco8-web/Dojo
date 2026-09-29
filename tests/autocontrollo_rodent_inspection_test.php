<?php

require_once __DIR__ . '/../core/autocontrollo_rodent.php';

$range = ['start' => '2026-05-01', 'end' => '2026-06-01'];
$schedule = autocontrollo_rodent_schedule($range);
if ($schedule !== ['2026-05-01', '2026-05-16', '2026-05-31']) {
    throw new RuntimeException('La pianificazione ogni 15 giorni non è corretta.');
}
if (autocontrollo_rodent_next_date($schedule, ['2026-05-01']) !== '2026-05-16') {
    throw new RuntimeException('La prossima procedura non rispetta la sequenza prevista.');
}

$page = file_get_contents(__DIR__ . '/../autocontrollo_derattizzazione.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
$mailer = file_get_contents(__DIR__ . '/../core/autocontrollo_rodent.php');
$javascript = file_get_contents(__DIR__ . '/../assets/autocontrollo-rodent.js');
$requirements = [
    [$page, "['bait_present','È già presente un’esca?']", 'presenza esca'],
    [$page, "['bait_eaten','L’esca risulta mangiata?']", 'esca mangiata'],
    [$page, "['bait_replaced','L’esca è stata sostituita?']", 'esca sostituita'],
    [$page, 'Rilevazioni effettuate', 'tabella procedure'],
    [$database, 'ensure_autocontrollo_rodent_inspections_tables', 'tabelle derattizzazione'],
    [$header, '/autocontrollo_derattizzazione.php', 'voce menu'],
    [$mailer, 'autocontrollo_rodent_send_report', 'invio rapporto email'],
    [$javascript, 'baitEatenQuestion', 'domanda condizionale'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if ($source === false || strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Procedura di derattizzazione quindicinale verificata.\n";
