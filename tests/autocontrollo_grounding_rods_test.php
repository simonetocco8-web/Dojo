<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file della mappatura paline.');

$requirements = [
    [$settings, 'Mappatura Paline Messa a Terra', 'box impostazioni'],
    [$settings, 'grounding_rod_create', 'creazione palina'],
    [$settings, 'grounding_rod_update', 'modifica palina'],
    [$settings, 'grounding_rod_delete', 'eliminazione palina'],
    [$settings, 'name="grounding_rod_location"', 'campo location'],
    [$database, 'ensure_autocontrollo_grounding_rods_table', 'inizializzazione tabella'],
    [$database, 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'ID autoincrementale'],
    [$database, 'autocontrollo_grounding_rods', 'persistenza paline'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Mappatura paline messa a terra verificata.\n";
