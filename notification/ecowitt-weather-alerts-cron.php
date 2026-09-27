#!/usr/bin/php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo processo può essere eseguito solo da CLI.\n");
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/ecowitt_alerts.php';

date_default_timezone_set('Europe/Rome');
$env = require __DIR__ . '/../config/env.php';
$result = send_ecowitt_weather_alerts(db(), $env);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['errors'] ? 1 : 0);
