<?php

$page = file_get_contents(__DIR__ . '/../autocontrollo_impianto_elettrico.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$javascript = file_get_contents(__DIR__ . '/../assets/autocontrollo-electrical.js');

foreach ([$page, $database, $javascript] as $source) {
    if ($source === false) throw new RuntimeException('Impossibile leggere i file dell’autocontrollo elettrico.');
}

$requirements = [
    [$page, "action === 'resolve_anomaly'", 'azione di risoluzione'],
    [$page, 'Anomalie Risolte', 'stato riepilogativo'],
    [$page, 'name="resolution_date"', 'data di risoluzione'],
    [$database, 'anomaly_resolved_date', 'persistenza della risoluzione'],
    [$javascript, "querySelectorAll('.show-resolution')", 'apertura del campo data'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException('Funzionalità mancante: ' . $label);
    }
}

echo "Risoluzione anomalie autocontrollo elettrico verificata.\n";
