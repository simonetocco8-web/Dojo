<?php
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
if ($dashboard === false) throw new RuntimeException('Dashboard non leggibile.');
foreach (['$isDayOffToday', "'table-warning'", '>Oggi</span>'] as $needle) {
    if (!str_contains($dashboard, $needle)) throw new RuntimeException('Evidenza giorno libero odierno mancante: ' . $needle);
}
echo "Evidenza giorni liberi odierni verificata.\n";
