<?php

$page = file_get_contents(__DIR__ . '/../autocontrollo_piscina.php');
$pdf = file_get_contents(__DIR__ . '/../reports/autocontrollo_pool_pdf.php');
if ($page === false || $pdf === false) throw new RuntimeException('Impossibile leggere i file del report piscina.');

$requirements = [
    [$page, '/reports/autocontrollo_pool_pdf.php', 'azione export PDF'],
    [$page, 'name="date_from"', 'data iniziale'],
    [$page, 'name="date_to"', 'data finale'],
    [$page, 'operator_name', 'nome operatore'],
    [$page, 'p.quantity_kg > 0', 'esclusione prodotti a zero'],
    [$pdf, 'BETWEEN ? AND ?', 'filtro intervallo date'],
    [$pdf, "setPaper('A4', 'landscape')", 'PDF orizzontale'],
    [$pdf, "user_has_department(\$user, 'Amministrazione')", 'protezione Amministrazione'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Tabella ed export PDF dei controlli piscina verificati.\n";
