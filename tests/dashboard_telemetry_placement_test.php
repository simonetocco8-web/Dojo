<?php

$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
if ($dashboard === false) {
    fwrite(STDERR, "Impossibile leggere dashboard.php\n");
    exit(1);
}

$legacyMarkers = [
    'Stazione meteo Ecowitt',
    'Temperatura acqua',
    'Aggiornata alle',
    'border-start border-4 border-info',
    'border-start border-4 border-danger',
];

foreach ($legacyMarkers as $marker) {
    if (str_contains($dashboard, $marker)) {
        fwrite(STDERR, "Markup telemetria legacy ancora presente in dashboard.php: {$marker}\n");
        exit(1);
    }
}

$include = "include __DIR__ . '/partials/dashboard_telemetry.php'";
if (substr_count($dashboard, $include) !== 1) {
    fwrite(STDERR, "Il partial telemetria deve essere incluso una sola volta.\n");
    exit(1);
}

$taskPosition = strpos($dashboard, '<!-- BOX TASK -->');
$telemetryPosition = strpos($dashboard, $include);
$forecastPosition = strpos($dashboard, 'Previsioni Meteo');
if ($taskPosition === false || $telemetryPosition === false || $forecastPosition === false
    || !($taskPosition < $telemetryPosition && $telemetryPosition < $forecastPosition)) {
    fwrite(STDERR, "Ordine delle sezioni dashboard non valido.\n");
    exit(1);
}

echo "Markup telemetria legacy assente; partial presente solo prima delle previsioni.\n";
