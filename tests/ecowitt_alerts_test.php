<?php

require_once __DIR__ . '/../core/ecowitt_alerts.php';

$active = ecowitt_weather_alert_definitions(['wind_gust' => 30.1, 'pressure' => 1013]);
if (!$active['wind']['active'] || !$active['pressure']['active']) {
    throw new RuntimeException('Le soglie di allerta non si attivano correttamente.');
}

$normal = ecowitt_weather_alert_definitions(['wind_gust' => 30, 'pressure' => 1013.1]);
if ($normal['wind']['active'] || $normal['pressure']['active']) {
    throw new RuntimeException('Le soglie di allerta si attivano su valori normali.');
}

foreach ($active as $alert) {
    if (sms_utf8_length(sms_gsm7_sanitize($alert['message'])) > 160) {
        throw new RuntimeException('Un messaggio di allerta supera il limite di un SMS.');
    }
}

echo "Soglie Ecowitt e lunghezza SMS verificate.\n";
