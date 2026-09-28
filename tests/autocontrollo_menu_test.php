<?php

$header = file_get_contents(__DIR__ . '/../partials/header.php');
if ($header === false) throw new RuntimeException('Impossibile leggere il menu.');

$entries = ['Derattizzazione', 'Messa a Terra', 'Antincendio', 'Piscina', 'Temperature', 'Pulizia HACCP', 'Impianto Elettrico', 'Setting', 'Report'];
foreach (['autocontrolloSidebarDropdown', 'autocontrolloDropdown'] as $menuId) {
    $start = strpos($header, 'aria-labelledby="' . $menuId . '"');
    $end = $start === false ? false : strpos($header, '</ul>', $start);
    $menu = ($start !== false && $end !== false) ? substr($header, $start, $end - $start) : '';
    foreach ($entries as $entry) {
        if (!str_contains($menu, '<span>' . $entry . '</span>')) {
            throw new RuntimeException('Voce Autocontrollo mancante in ' . $menuId . ': ' . $entry);
        }
    }
}
if (substr_count($header, '<span>Autocontrollo</span>') !== 2) {
    throw new RuntimeException('Il modulo Autocontrollo deve comparire nei menu desktop e mobile.');
}

echo "Menu Autocontrollo desktop e mobile verificati.\n";
