<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file della mappatura trappole roditori.');

$requirements = [
    [$settings, 'Mappatura Trappole Roditori', 'sezione impostazioni'],
    [$settings, 'rodent_trap_create', 'creazione trappola'],
    [$settings, 'rodent_trap_update', 'modifica trappola'],
    [$settings, 'rodent_trap_delete', 'eliminazione trappola'],
    [$settings, 'trap_location', 'location trappola'],
    [$settings, 'assegnato automaticamente', 'ID autoincrementale'],
    [$database, 'ensure_autocontrollo_rodent_traps_table', 'inizializzazione tabella'],
    [$database, 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'chiave automatica'],
    [$database, 'DROP COLUMN trap_identifier', 'migrazione schema precedente'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

if (strpos($settings, "SELECT id, trap_identifier, location FROM autocontrollo_rodent_traps") !== false) {
    throw new RuntimeException('La pagina non deve più leggere la colonna trap_identifier.');
}

echo "Gestione mappatura trappole roditori verificata.\n";
