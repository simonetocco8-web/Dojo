<?php

require_once __DIR__ . '/../core/ecowitt_alerts.php';
require_once __DIR__ . '/../core/boiler_alerts.php';

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

$boilerMessage = boiler_temperature_alert_message('Boiler Appartamenti', 45, 50);
if (sms_utf8_length(sms_gsm7_sanitize($boilerMessage)) > 160) {
    throw new RuntimeException('Il messaggio di allerta caldaia supera il limite di un SMS.');
}
if (!boiler_temperature_is_below_setpoint(50, 50) || !boiler_temperature_is_below_setpoint(49.9, 50)
    || boiler_temperature_is_below_setpoint(50.1, 50) || boiler_temperature_is_below_setpoint(null, 50)) {
    throw new RuntimeException('La soglia di temperatura caldaie non è valutata correttamente.');
}
