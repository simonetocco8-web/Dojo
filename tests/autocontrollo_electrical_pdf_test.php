<?php

$page = file_get_contents(__DIR__ . '/../autocontrollo_impianto_elettrico.php');
$pdf = file_get_contents(__DIR__ . '/../reports/autocontrollo_electrical_pdf.php');
if ($page === false || $pdf === false) throw new RuntimeException('Impossibile leggere i file PDF dell’autocontrollo.');

$requirements = [
    [$page, '/reports/autocontrollo_electrical_pdf.php?id=', 'pulsante PDF nel riepilogo'],
    [$page, 'bi-file-earmark-pdf', 'icona PDF'],
    [$pdf, "user_has_department(\$user, 'Amministrazione')", 'protezione Amministrazione'],
    [$pdf, "setPaper('A4', 'landscape')", 'formato PDF'],
    [$pdf, 'anomaly_resolved_date', 'stato risoluzione anomalie'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Export PDF autocontrollo elettrico verificato.\n";
