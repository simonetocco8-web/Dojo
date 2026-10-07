<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file della mappatura frigoriferi.');

$requirements = [
    [$settings, 'Mappatura Frigoriferi', 'box impostazioni'],
    [$settings, 'refrigerator_create', 'creazione frigorifero'],
    [$settings, 'refrigerator_update', 'modifica frigorifero'],
    [$settings, 'refrigerator_delete', 'eliminazione frigorifero'],
    [$settings, 'original_refrigerator_id', 'modifica ID univoco'],
    [$settings, "['frigorifero', 'congelatore', 'cella']", 'tipologie consentite'],
    [$settings, 'name="operating_temperature"', 'temperatura esercizio'],
    [$database, 'ensure_autocontrollo_refrigerators_table', 'inizializzazione tabella'],
    [$database, 'id VARCHAR(50) NOT NULL PRIMARY KEY', 'ID utente univoco'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Mappatura frigoriferi verificata.\n";
