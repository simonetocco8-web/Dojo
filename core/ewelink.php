<?php
// core/ewelink.php

require_once __DIR__ . '/db.php';

/**
 * Restituisce la configurazione eWeLink normalizzata.
 */
function ewelink_config(): array
{
    static $config = null;
    if ($config === null) {
        $env = require __DIR__ . '/../config/env.php';
        $config = $env['ewelink'] ?? [];
        $config = array_merge([
            'auth_base' => 'https://eu-apia.coolkit.cc',
            'api_base' => 'https://eu-apia.coolkit.cc',
            'scope' => 'userinfo:read device:read device:write',
        ], $config);
    }

    return $config;
}

function ewelink_is_configured(): bool
{
    $cfg = ewelink_config();
    return !empty($cfg['client_id']) && !empty($cfg['client_secret']) && !empty($cfg['redirect_uri']);
}

function ewelink_authorization_url(string $state, array $params = []): string
{
    if (!ewelink_is_configured()) {
        throw new RuntimeException('Configurazione OAuth eWeLink mancante.');
    }

    $cfg = ewelink_config();
    $base = rtrim($cfg['auth_base'] ?? '', '/');

    $query = array_merge([
        'response_type' => 'code',
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'scope' => $cfg['scope'] ?? '',
        'state' => $state,
    ], $params);

    return $base . '/oauth2/authorize?' . http_build_query($query);
}

function ewelink_token_request(array $params): array
{
    if (!ewelink_is_configured()) {
        throw new RuntimeException('Configurazione OAuth eWeLink mancante.');
    }

    $cfg = ewelink_config();
    $tokenUrl = rtrim($cfg['auth_base'] ?? '', '/') . '/oauth2/token';
    $payload = array_merge($params, [
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore nella richiesta token eWeLink: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Risposta non valida dal server eWeLink: ' . json_last_error_msg());
    }

    if ($status >= 400) {
        $message = $decoded['message'] ?? $decoded['error_description'] ?? 'Errore nella richiesta token (HTTP ' . $status . ')';
        throw new RuntimeException($message);
    }

    return $decoded ?? [];
}

function ewelink_exchange_code(string $code): array
{
    return ewelink_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => ewelink_config()['redirect_uri'],
    ]);
}

function ewelink_get_tokens(int $userId): ?array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM ewelink_tokens WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function ewelink_store_tokens(int $userId, array $tokens): void
{
    $pdo = db();
    $expiresAt = null;
    if (!empty($tokens['expires_in'])) {
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . (int)$tokens['expires_in'] . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    $st = $pdo->prepare('INSERT INTO ewelink_tokens (
            user_id, access_token, refresh_token, token_type, expires_at, scope, api_region, api_endpoint
        ) VALUES (
            :user_id, :access_token, :refresh_token, :token_type, :expires_at, :scope, :api_region, :api_endpoint
        )
        ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            token_type = VALUES(token_type),
            expires_at = VALUES(expires_at),
            scope = VALUES(scope),
            api_region = VALUES(api_region),
            api_endpoint = VALUES(api_endpoint),
            updated_at = CURRENT_TIMESTAMP');

    $st->execute([
        ':user_id' => $userId,
        ':access_token' => $tokens['access_token'] ?? '',
        ':refresh_token' => $tokens['refresh_token'] ?? null,
        ':token_type' => $tokens['token_type'] ?? null,
        ':expires_at' => $expiresAt,
        ':scope' => $tokens['scope'] ?? ($tokens['scopes'] ?? null),
        ':api_region' => $tokens['region'] ?? ($tokens['api_region'] ?? null),
        ':api_endpoint' => $tokens['api_endpoint'] ?? ($tokens['endpoint'] ?? null),
    ]);
}

function ewelink_delete_tokens(int $userId): void
{
    $pdo = db();
    $st = $pdo->prepare('DELETE FROM ewelink_tokens WHERE user_id = ?');
    $st->execute([$userId]);
}

function ewelink_refresh_tokens(int $userId): ?array
{
    $current = ewelink_get_tokens($userId);
    if (!$current || empty($current['refresh_token'])) {
        throw new RuntimeException('Nessun refresh token disponibile.');
    }

    $tokens = ewelink_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $current['refresh_token'],
    ]);
    ewelink_store_tokens($userId, $tokens);

    return ewelink_get_tokens($userId);
}

function ewelink_ensure_access_token(int $userId): ?array
{
    $tokens = ewelink_get_tokens($userId);
    if (!$tokens) {
        return null;
    }

    if (!empty($tokens['expires_at'])) {
        try {
            $expires = new DateTimeImmutable($tokens['expires_at']);
            $threshold = (new DateTimeImmutable())->modify('+60 seconds');
            if ($expires <= $threshold) {
                $tokens = ewelink_refresh_tokens($userId) ?? $tokens;
            }
        } catch (Exception $e) {
            // Se la data non è valida, forziamo un refresh
            $tokens = ewelink_refresh_tokens($userId) ?? $tokens;
        }
    }

    return $tokens;
}

function ewelink_resolve_api_base(array $tokens = []): string
{
    $cfg = ewelink_config();
    if (!empty($tokens['api_endpoint'])) {
        return rtrim($tokens['api_endpoint'], '/');
    }
    if (!empty($tokens['api_region'])) {
        return 'https://' . $tokens['api_region'] . '-apia.coolkit.cc';
    }
    return rtrim($cfg['api_base'] ?? 'https://eu-apia.coolkit.cc', '/');
}

function ewelink_api_request(int $userId, string $method, string $path, ?array $body = null, array $query = [], bool $retry = false): array
{
    $tokens = ewelink_ensure_access_token($userId);
    if (!$tokens || empty($tokens['access_token'])) {
        throw new RuntimeException('Account eWeLink non collegato.');
    }

    $url = ewelink_resolve_api_base($tokens) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'Authorization: Bearer ' . $tokens['access_token'],
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if ($body !== null) {
        $json = json_encode($body);
        if ($json === false) {
            throw new RuntimeException('Impossibile serializzare il payload JSON.');
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore nella richiesta eWeLink: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Risposta JSON non valida da eWeLink: ' . json_last_error_msg());
    }

    if ($status === 401 && !$retry) {
        ewelink_refresh_tokens($userId);
        return ewelink_api_request($userId, $method, $path, $body, $query, true);
    }

    if ($status >= 400) {
        $message = $decoded['message'] ?? $decoded['error'] ?? 'Errore eWeLink (HTTP ' . $status . ')';
        throw new RuntimeException($message);
    }

    return $decoded ?? [];
}

function ewelink_fetch_devices(int $userId): array
{
    $response = ewelink_api_request($userId, 'GET', '/v2/device/thing');
    $data = $response['data'] ?? [];
    $things = $data['thingList'] ?? [];

    $devices = [];
    foreach ($things as $thing) {
        $itemData = $thing['itemData'] ?? [];
        $deviceId = $itemData['deviceid'] ?? ($thing['deviceid'] ?? ($thing['id'] ?? ''));
        $name = $itemData['name'] ?? ($thing['name'] ?? $deviceId);
        $online = $itemData['online'] ?? ($thing['online'] ?? null);
        $params = $thing['params'] ?? ($itemData['params'] ?? []);

        $devices[] = [
            'id' => $deviceId,
            'name' => $name,
            'online' => $online,
            'params' => $params,
            'raw' => $thing,
        ];
    }

    return [
        'devices' => $devices,
        'raw' => $response,
    ];
}

function ewelink_control_device(int $userId, string $deviceId, array $params): array
{
    if (!$deviceId) {
        throw new RuntimeException('ID dispositivo non valido.');
    }
    if (empty($params)) {
        throw new RuntimeException('Parametri di controllo mancanti.');
    }

    $payload = [
        'thingList' => [[
            'itemType' => 1,
            'id' => $deviceId,
            'params' => $params,
        ]],
    ];

    return ewelink_api_request($userId, 'POST', '/v2/device/thing/status', $payload);
}
