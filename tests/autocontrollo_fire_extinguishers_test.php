<?php
$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$install = file_get_contents(__DIR__ . '/../database/install.sql');
foreach ([
    [$settings, '>Estintori<', 'sezione Estintori'],
    [$settings, 'extinguisher_create', 'creazione estintore'],
    [$settings, 'extinguisher_update', 'modifica estintore'],
    [$settings, 'extinguisher_delete', 'eliminazione estintore'],
    [$settings, "['polvere', 'co2', 'schiuma', 'carrellato']", 'tipologie consentite'],
    [$settings, 'name="capacity_kg"', 'capacità in Kg'],
    [$database, 'ensure_autocontrollo_fire_extinguishers_table', 'inizializzazione tabella'],
    [$install, 'autocontrollo_fire_extinguishers', 'schema di installazione'],
] as [$source, $needle, $label]) if ($source === false || !str_contains($source, $needle)) throw new RuntimeException('Funzionalità mancante: ' . $label);
echo "Gestione estintori verificata.\n";
