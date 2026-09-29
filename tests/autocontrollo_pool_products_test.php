<?php

$settings = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$database = file_get_contents(__DIR__ . '/../core/db.php');
if ($settings === false || $database === false) throw new RuntimeException('Impossibile leggere i file dei prodotti piscina.');

$requirements = [
    [$settings, 'Prodotti Piscina', 'box impostazioni'],
    [$settings, 'pool_product_create', 'creazione prodotto'],
    [$settings, 'pool_product_update', 'modifica prodotto'],
    [$settings, 'pool_product_delete', 'eliminazione prodotto'],
    [$settings, 'maxlength="255"', 'limite descrizione'],
    [$database, 'ensure_autocontrollo_pool_products_table', 'inizializzazione tabella'],
    [$database, 'autocontrollo_pool_products', 'persistenza prodotti'],
];
foreach ($requirements as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) throw new RuntimeException('Funzionalità mancante: ' . $label);
}

echo "Gestione prodotti piscina verificata.\n";
