<?php

$header = file_get_contents(__DIR__ . '/../partials/header.php');
$electrical = file_get_contents(__DIR__ . '/../autocontrollo_impianto_elettrico.php');
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
if (substr_count($header, "user_has_department(\$user, 'Amministrazione')") < 2) {
    throw new RuntimeException('Il modulo Autocontrollo non è limitato al dipartimento Amministrazione.');
}
if (substr_count($header, '/autocontrollo_impianto_elettrico.php') !== 2) {
    fwrite(STDERR, "La voce Impianto Elettrico deve puntare alla pagina in entrambi i menu.\n");
    exit(1);
}
if (strpos($electrical, "user_has_department(\$user, 'Amministrazione')") === false) {
    fwrite(STDERR, "La pagina Impianto Elettrico deve essere riservata ad Amministrazione.\n");
    exit(1);
}
if (strpos($electrical, '/assets/autocontrollo-electrical.js') === false || strpos($electrical, '<script>document.querySelectorAll') !== false) {
    fwrite(STDERR, "La gestione dell'anomalia deve usare uno script esterno compatibile con la CSP.\n");
    exit(1);
}

echo "Menu Autocontrollo desktop e mobile verificati.\n";
