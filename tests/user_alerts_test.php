<?php
require_once __DIR__ . '/../core/user_alerts.php';
$alerts = user_alerts_weather(['pressure'=>1005.5,'wind_gust'=>30.1], 1005.5);
if (count($alerts) !== 2 || $alerts[1]['title'] !== 'Allerta pressione atmosferica') throw new RuntimeException('Weather alerts missing.');
if (user_alerts_weather(['pressure'=>1005.6,'wind_gust'=>30],1005.5) !== []) throw new RuntimeException('Normal weather generated alerts.');
if (user_alerts_weather(['pressure'=>null,'wind_gust'=>null],1013) !== []) throw new RuntimeException('Missing readings generated alerts.');
echo "Campanella: allerte meteo e soglia configurabile verificate.\n";
