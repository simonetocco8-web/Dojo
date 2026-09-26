<?php

function ecowitt_config(): array
{
    $env = require __DIR__ . '/../config/env.php';
    return $env['ecowitt'] ?? [];
}

function ecowitt_is_configured(): bool
{
    $cfg = ecowitt_config();
    return !empty($cfg['application_key']) && !empty($cfg['api_key']) && !empty($cfg['device_mac']);
}

/**
 * Recupera la temperatura esterna corrente in gradi Celsius.
 */
function ecowitt_fetch_temperature(): array
{
    $cfg = ecowitt_config();
    $output = ['configured' => ecowitt_is_configured(), 'temperature' => null, 'measured_at' => null, 'error' => null];
    if (!$output['configured']) return $output;

    $cacheSeconds = max(0, (int)($cfg['cache_seconds'] ?? 60));
    $cacheFile = rtrim(sys_get_temp_dir(), '/') . '/dojo-ecowitt-' . hash('sha256', (string)$cfg['device_mac']) . '.json';
    if ($cacheSeconds > 0 && is_file($cacheFile) && filemtime($cacheFile) >= time() - $cacheSeconds) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return array_merge($output, $cached);
    }

    $query = http_build_query([
        'application_key' => $cfg['application_key'],
        'api_key' => $cfg['api_key'],
        'mac' => $cfg['device_mac'],
        'call_back' => 'outdoor.temperature',
        'temp_unitid' => 1,
    ]);
    $url = rtrim((string)$cfg['api_base'], '/') . '/device/real_time?' . $query;

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(2, (int)($cfg['timeout_seconds'] ?? 10)),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Connessione a Ecowitt non riuscita: ' . $message);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) throw new RuntimeException('API Ecowitt non disponibile (HTTP ' . $status . ').');

        $response = json_decode($body, true);
        if (!is_array($response)) throw new RuntimeException('Risposta JSON Ecowitt non valida.');
        if ((int)($response['code'] ?? -1) !== 0) {
            throw new RuntimeException((string)($response['msg'] ?? 'Errore API Ecowitt.'));
        }
        $reading = $response['data']['outdoor']['temperature'] ?? null;
        if (!is_array($reading) || !isset($reading['value']) || !is_numeric($reading['value'])) {
            throw new RuntimeException('La temperatura esterna non è presente nella risposta Ecowitt.');
        }
        $output['temperature'] = (float)$reading['value'];
        $output['measured_at'] = isset($reading['time']) && is_numeric($reading['time']) ? (int)$reading['time'] : null;
        @file_put_contents($cacheFile, json_encode($output), LOCK_EX);
    } catch (Throwable $e) {
        $output['error'] = $e->getMessage();
        error_log('[Ecowitt] ' . $output['error']);
    }
    return $output;
}
