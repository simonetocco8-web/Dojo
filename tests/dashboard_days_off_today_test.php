<?php
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
if ($dashboard === false) throw new RuntimeException('Dashboard non leggibile.');
foreach (['$isDayOffToday', "'table-warning'", '>Oggi</span>'] as $needle) {
    if (!str_contains($dashboard, $needle)) throw new RuntimeException('Evidenza giorno libero odierno mancante: ' . $needle);
}
if (!str_contains($dashboard, 'AND u.is_active = 1') || !str_contains($dashboard, 'AND u.deleted_at IS NULL')) {
    throw new RuntimeException('Gli utenti non attivi devono essere esclusi dal box Giorni liberi.');
}
echo "Evidenza giorni liberi odierni verificata.\n";
