<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file della mappatura trappole.');

$requirements = [
    [$settings, 'Mappatura Trappole Roditori', 'box impostazioni'],
    [$settings, 'rodent_trap_create', 'creazione trappola'],
    [$settings, 'rodent_trap_update', 'modifica trappola'],
    [$settings, 'rodent_trap_delete', 'eliminazione trappola'],
    [$settings, 'name="trap_location"', 'campo location'],
    [$database, 'ensure_autocontrollo_rodent_traps_table', 'inizializzazione tabella'],
    [$database, 'autocontrollo_rodent_traps', 'persistenza trappole'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Mappatura trappole roditori verificata.\n";
