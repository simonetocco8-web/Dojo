<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file della mappatura trappole roditori.');

$requirements = [
    [$settings, 'Mappatura Trappole Roditori', 'sezione impostazioni'],
    [$settings, 'rodent_trap_create', 'creazione trappola'],
    [$settings, 'rodent_trap_update', 'modifica trappola'],
    [$settings, 'rodent_trap_delete', 'eliminazione trappola'],
    [$settings, 'trap_identifier', 'ID trappola'],
    [$settings, 'trap_location', 'location trappola'],
    [$database, 'ensure_autocontrollo_rodent_traps_table', 'inizializzazione tabella'],
    [$database, 'UNIQUE KEY uq_rodent_trap_identifier', 'unicità ID trappola'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Gestione mappatura trappole roditori verificata.\n";
