<?php

/**
 * Client minimale per gli endpoint MCP Streamable HTTP di eWeLink.
 * L'URL di accesso contiene una credenziale e deve restare in una variabile d'ambiente.
 */

function ewelink_mcp_config(): array
{
    $env = require __DIR__ . '/../config/env.php';
    return $env['ewelink'] ?? [];
}

function ewelink_mcp_trace(array &$trace, string $step, string $message, array $context = []): void
{
    $trace[] = [
        'time' => date('H:i:s'),
        'step' => $step,
        'message' => $message,
        'context' => $context,
    ];
}

function ewelink_mcp_debug_preview(array $result): string
{
    $preview = substr((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 4000);
    $preview = preg_replace('/(https?:\/\/[^?"\s]+)\?[^"\s]+/i', '$1?[REDACTED]', $preview);
    return preg_replace('/(bearer|token|access_token)["\s:=]+[^,"\s}]+/i', '$1=[REDACTED]', (string)$preview);
}

function ewelink_mcp_decode_response(string $body): array
{
    $decoded = json_decode($body, true);
    if (is_array($decoded)) return $decoded;

    // Alcuni server MCP restituiscono gli stessi messaggi JSON-RPC tramite SSE.
    foreach (preg_split('/\r?\n/', $body) as $line) {
        if (str_starts_with($line, 'data:')) {
            $decoded = json_decode(trim(substr($line, 5)), true);
            if (is_array($decoded)) return $decoded;
        }
    }
    throw new RuntimeException('Il server MCP ha restituito una risposta non valida.');
}

function ewelink_mcp_request(string $method, array $params = [], ?string &$sessionId = null, ?array &$trace = null): array
{
    $cfg = ewelink_mcp_config();
    $url = trim((string)($cfg['mcp_access_url'] ?? ''));
    if ($url === '') throw new RuntimeException('MCP eWeLink non configurato.');
    if ($trace !== null) ewelink_mcp_trace($trace, 'request', 'Invio ' . $method, ['session' => $sessionId ? 'presente' : 'assente']);

    $message = [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => (object)$params,
    ];
    $isNotification = str_starts_with($method, 'notifications/');
    if (!$isNotification) $message['id'] = random_int(1, PHP_INT_MAX);
    $payload = json_encode($message, JSON_UNESCAPED_SLASHES);
    $responseHeaders = [];
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-03-26'];
    if ($sessionId) $headers[] = 'Mcp-Session-Id: ' . $sessionId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTREDIR => CURL_REDIR_POST_ALL,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(2, (int)($cfg['mcp_timeout_seconds'] ?? 12)),
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $length;
        },
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $message = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Connessione al server MCP non riuscita: ' . $message);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($trace !== null) ewelink_mcp_trace($trace, 'response', 'Risposta a ' . $method, [
        'http_status' => $status,
        'content_type' => $contentType,
        'bytes' => strlen($body),
        'session' => !empty($responseHeaders['mcp-session-id']) ? 'ricevuta' : 'non ricevuta',
    ]);
    if ($status >= 400) throw new RuntimeException('Server MCP non disponibile (HTTP ' . $status . ').');
    if (!empty($responseHeaders['mcp-session-id'])) $sessionId = $responseHeaders['mcp-session-id'];
    if ($isNotification && trim($body) === '') return [];

    $decoded = ewelink_mcp_decode_response($body);
    if (!empty($decoded['error'])) {
        if ($trace !== null) ewelink_mcp_trace($trace, 'error', 'Errore JSON-RPC per ' . $method, [
            'code' => $decoded['error']['code'] ?? null,
            'message' => $decoded['error']['message'] ?? 'Errore MCP eWeLink.',
        ]);
        throw new RuntimeException((string)($decoded['error']['message'] ?? 'Errore MCP eWeLink.'));
    }
    return $decoded['result'] ?? [];
}

function ewelink_mcp_flatten($value): array
{
    $values = [$value];
    if (is_array($value)) {
        foreach ($value as $child) $values = array_merge($values, ewelink_mcp_flatten($child));
    } elseif (is_string($value)) {
        $json = json_decode($value, true);
        if (is_array($json)) $values = array_merge($values, ewelink_mcp_flatten($json));
    }
    return $values;
}

function ewelink_mcp_temperature_from_result(array $result, string $deviceName, bool $requireDeviceName = true): ?float
{
    $temperatureKeys = ['temperature', 'currenttemperature', 'current_temperature', 'temp'];
    $findTemperature = static function ($value) use (&$findTemperature, $temperatureKeys): ?float {
        if (is_string($value)) {
            if (preg_match('/(?:temperature|temperatura|temp)[^0-9-]{0,20}(-?\d+(?:[.,]\d+)?)/i', $value, $match)
                || preg_match('/(-?\d+(?:[.,]\d+)?)\s*°\s*C\b/i', $value, $match)) {
                return (float)str_replace(',', '.', $match[1]);
            }
            return null;
        }
        if (!is_array($value)) return null;
        foreach ($value as $key => $child) {
            if (in_array(strtolower((string)$key), $temperatureKeys, true) && is_numeric($child)) return (float)$child;
            $found = $findTemperature($child);
            if ($found !== null) return $found;
        }
        return null;
    };
    foreach (ewelink_mcp_flatten($result) as $candidate) {
        if (!is_array($candidate)) continue;
        $encoded = json_encode($candidate, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || ($requireDeviceName && stripos($encoded, $deviceName) === false)) continue;
        $temperature = $findTemperature($candidate);
        if ($temperature !== null) return $temperature;
    }
    return null;
}

function ewelink_mcp_device_id_from_result(array $result, string $deviceName): ?string
{
    foreach (ewelink_mcp_flatten($result) as $candidate) {
        if (!is_array($candidate)) continue;
        $encoded = json_encode($candidate, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || stripos($encoded, $deviceName) === false) continue;
        foreach ($candidate as $key => $value) {
            $normalized = strtolower(str_replace(['-', '_'], '', (string)$key));
            if (in_array($normalized, ['id', 'deviceid', 'thingid'], true) && is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
    }
    return null;
}

function ewelink_mcp_arguments(array $schema, string $deviceName, ?string $deviceId = null): ?array
{
    $properties = $schema['properties'] ?? [];
    $required = $schema['required'] ?? [];
    $arguments = [];
    foreach ($properties as $name => $definition) {
        $normalized = strtolower((string)$name);
        $compact = str_replace(['-', '_'], '', $normalized);
        if ($deviceId !== null && in_array($compact, ['id', 'deviceid', 'thingid'], true)) {
            $arguments[$name] = $deviceId;
        } elseif (str_contains($normalized, 'name') || str_contains($normalized, 'query')) {
            $arguments[$name] = $deviceName;
        } elseif (str_contains($normalized, 'device') && !str_contains($normalized, 'id')) {
            $arguments[$name] = $deviceName;
        }
    }
    foreach ($required as $name) {
        if (!array_key_exists($name, $arguments)) return null;
    }
    return $arguments;
}

function ewelink_mcp_fetch_boilers(bool $debug = false): array
{
    $cfg = ewelink_mcp_config();
    $names = $cfg['mcp_boiler_names'] ?? ['Boiler Appartamenti', 'Boiler Cottage'];
    $trace = [];
    if (empty($cfg['mcp_access_url'])) return ['configured' => false, 'boilers' => [], 'error' => null, 'trace' => $trace];
    ewelink_mcp_trace($trace, 'config', 'Configurazione MCP caricata', [
        'device_names' => $names,
        'timeout_seconds' => (int)($cfg['mcp_timeout_seconds'] ?? 12),
    ]);

    $cacheSeconds = max(0, (int)($cfg['mcp_cache_seconds'] ?? 60));
    $cacheFile = rtrim(sys_get_temp_dir(), '/') . '/dojo-ewelink-mcp-' . hash('sha256', (string)$cfg['mcp_access_url']) . '.json';
    if (!$debug && $cacheSeconds > 0 && is_file($cacheFile) && filemtime($cacheFile) >= time() - $cacheSeconds) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['trace'] = [['time' => date('H:i:s'), 'step' => 'cache', 'message' => 'Risultato caricato dalla cache', 'context' => ['age_seconds' => time() - filemtime($cacheFile)]]];
            return $cached;
        }
    }

    if ($debug) ewelink_mcp_trace($trace, 'cache', 'Cache ignorata per il debug');
    $output = ['configured' => true, 'boilers' => array_fill_keys($names, null), 'error' => null, 'trace' => &$trace];
    try {
        $session = null;
        ewelink_mcp_request('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object)[],
            'clientInfo' => ['name' => 'Dojo Dashboard', 'version' => '1.0'],
        ], $session, $trace);
        ewelink_mcp_request('notifications/initialized', [], $session, $trace);
        $toolsResult = ewelink_mcp_request('tools/list', [], $session, $trace);
        $tools = $toolsResult['tools'] ?? [];
        ewelink_mcp_trace($trace, 'tools', 'Strumenti MCP ricevuti', $debug ? [
            'tools' => array_map(static fn(array $tool): array => [
                'name' => $tool['name'] ?? '',
                'description' => $tool['description'] ?? '',
                'inputSchema' => $tool['inputSchema'] ?? null,
            ], $tools),
        ] : ['names' => array_column($tools, 'name')]);
        $tools = array_values(array_filter($tools, static function (array $tool): bool {
            $text = ($tool['name'] ?? '') . ' ' . ($tool['description'] ?? '');
            return preg_match('/device|thing|status|state|temperature|sensor|list|get|query|read/i', $text)
                && !preg_match('/control|command|switch|turn.on|turn.off|write|update|delete|remove|set./i', $text);
        }));
        usort($tools, static function (array $a, array $b): int {
            $score = static fn(array $tool): int => preg_match('/status|state|device|thing|list/i', ($tool['name'] ?? '') . ' ' . ($tool['description'] ?? '')) ? 0 : 1;
            return $score($a) <=> $score($b);
        });
        $tools = array_slice($tools, 0, 8);
        $deviceIds = array_fill_keys($names, null);

        // Prima interroga gli strumenti senza argomenti (tipicamente "list devices"):
        // servono sia a leggere direttamente i sensori sia a risolvere nome -> device ID.
        foreach ($tools as $tool) {
            if (!empty($tool['inputSchema']['required'])) continue;
            try {
                $result = ewelink_mcp_request('tools/call', ['name' => $tool['name'], 'arguments' => (object)[]], $session, $trace);
                if ($debug) ewelink_mcp_trace($trace, 'tool_result', 'Risultato di ' . $tool['name'], [
                    'preview' => ewelink_mcp_debug_preview($result),
                ]);
            } catch (Throwable $toolError) {
                ewelink_mcp_trace($trace, 'tool_error', 'Errore in ' . ($tool['name'] ?? 'tool'), ['message' => $toolError->getMessage()]);
                continue;
            }
            foreach ($names as $deviceName) {
                $temperature = ewelink_mcp_temperature_from_result($result, $deviceName);
                if ($temperature !== null) $output['boilers'][$deviceName] = $temperature;
                $deviceIds[$deviceName] = ewelink_mcp_device_id_from_result($result, $deviceName) ?? $deviceIds[$deviceName];
                ewelink_mcp_trace($trace, 'device', 'Analisi di ' . $deviceName, [
                    'device_id_found' => $deviceIds[$deviceName] !== null,
                    'temperature_found' => $output['boilers'][$deviceName] !== null,
                ]);
            }
        }
        foreach ($names as $deviceName) {
            if ($output['boilers'][$deviceName] !== null) continue;
            foreach ($tools as $tool) {
                $arguments = ewelink_mcp_arguments($tool['inputSchema'] ?? [], $deviceName, $deviceIds[$deviceName]);
                if ($arguments === null) continue;
                try {
                    ewelink_mcp_trace($trace, 'tool', 'Chiamata ' . $tool['name'], ['arguments' => $arguments]);
                    $result = ewelink_mcp_request('tools/call', ['name' => $tool['name'], 'arguments' => (object)$arguments], $session, $trace);
                    if ($debug) ewelink_mcp_trace($trace, 'tool_result', 'Risultato di ' . $tool['name'], [
                        'preview' => ewelink_mcp_debug_preview($result),
                    ]);
                } catch (Throwable $toolError) {
                    ewelink_mcp_trace($trace, 'tool_error', 'Errore in ' . ($tool['name'] ?? 'tool'), ['message' => $toolError->getMessage()]);
                    continue;
                }
                // Una risposta richiesta per ID spesso contiene solo i parametri
                // (es. {"temperature": 48.2}) e non ripete il nome dispositivo.
                $temperature = ewelink_mcp_temperature_from_result($result, $deviceName, false);
                if ($temperature !== null) {
                    $output['boilers'][$deviceName] = $temperature;
                    break;
                }
            }
        }
        $missing = array_keys(array_filter($output['boilers'], static fn($temperature): bool => $temperature === null));
        if ($missing) {
            $output['error'] = 'Nessuna temperatura ricevuta per: ' . implode(', ', $missing) . '.';
        }
    } catch (Throwable $e) {
        $output['error'] = $e->getMessage();
        ewelink_mcp_trace($trace, 'fatal', 'Comunicazione MCP interrotta', ['message' => $e->getMessage()]);
    }
    if ($output['error'] !== null) error_log('[eWeLink MCP] ' . $output['error']);
    if ($output['error'] === null || !is_file($cacheFile)) @file_put_contents($cacheFile, json_encode($output), LOCK_EX);
    return $output;
}
