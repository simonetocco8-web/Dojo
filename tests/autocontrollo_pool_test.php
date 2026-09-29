<?php

require_once __DIR__ . '/../core/autocontrollo_pool.php';

$range = ['start' => '2026-06-01', 'end' => '2026-06-04'];
if (autocontrollo_pool_next_required_date($range, []) !== '2026-06-01') {
    throw new RuntimeException('Il primo controllo deve coincidere con l’apertura.');
}
if (autocontrollo_pool_next_required_date($range, ['2026-06-01', '2026-06-03']) !== '2026-06-02') {
    throw new RuntimeException('Non deve essere possibile saltare una data precedente.');
}
if (autocontrollo_pool_next_required_date($range, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04']) !== null) {
    throw new RuntimeException('La stagione completa non deve proporre altri controlli.');
}

$page = file_get_contents(__DIR__ . '/../autocontrollo_piscina.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
$header = file_get_contents(__DIR__ . '/../partials/header.php');
$javascript = file_get_contents(__DIR__ . '/../assets/autocontrollo-pool.js');
$requirements = [
    [$page, 'name="chlorine"', 'cloro'], [$page, 'name="water_temperature"', 'temperatura'],
    [$page, 'name="ph_value"', 'pH'], [$page, 'product_quantities[', 'prodotti e quantità'],
    [$page, 'name="people_in_pool"', 'persone in vasca'], [$page, 'name="backwash_minutes"', 'controlavaggio'],
    [$page, 'name="sample_location"', 'punto di prelievo'],
    [$database, 'ensure_autocontrollo_pool_inspections_tables', 'tabelle controlli piscina'],
    [$header, '/autocontrollo_piscina.php', 'voce menu Piscina'],
    [$page, 'Auto Completamento', 'pulsante autocompletamento'],
    [$page, '/assets/autocontrollo-pool.js', 'script autocompletamento'],
    [$javascript, "setValue('chlorine'", 'copia dei parametri'],
    [$javascript, 'product_quantities[', 'copia dei prodotti'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if ($source === false || strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Procedura giornaliera di autocontrollo piscina verificata.\n";
