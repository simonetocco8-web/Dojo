<?php
// Simula db.php precedente: gli helper delle tabelle non sono ancora definiti.
$page = file_get_contents(__DIR__ . '/../autocontrollo_settings.php');
$include = "require_once __DIR__ . '/core/autocontrollo_schema.php';";
$position = strpos($page, $include);
$initialization = strpos($page, 'ensure_autocontrollo_refrigerators_table($pdo);');
if ($position === false || $initialization === false || $position >= $initialization) throw new RuntimeException('Schema di compatibilità non caricato prima dei setting.');
require_once __DIR__ . '/../core/autocontrollo_schema.php';
foreach (['electrical_panels','pool_products','rodent_traps','grounding_rods','refrigerators','fire_extinguishers'] as $table) {
    if (!function_exists('ensure_autocontrollo_' . $table . '_table')) throw new RuntimeException('Helper setting mancante: ' . $table);
}
echo "Helper setting caricati anche senza definizioni nel vecchio db.php.\n";
