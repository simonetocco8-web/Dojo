<?php

function sms_send_internal_transfer(array $env, array $payload): void {
  $cfg = $env['sms'] ?? [];
  if (empty($cfg['enabled'])) return;

  $apiKey = (string)($cfg['api_key'] ?? '');
  $endpoint = (string)($cfg['endpoint'] ?? 'https://sms.openapi.com/v1/sms/send');
  $to = (string)($cfg['to'] ?? '');
  $sender = (string)($cfg['sender'] ?? 'Dojo');
  $authMode = strtolower((string)($cfg['auth_mode'] ?? 'auto'));

  if ($apiKey === '' || $to === '') {
    throw new RuntimeException('Configurazione SMS incompleta (api_key/to).');
  }

  $endpoint = str_replace('://api.openapi.com/', '://sms.openapi.com/', $endpoint);

  $message = sprintf(
    'Nuovo transfer interno: Camera %s %s %s - Data %s Ora %s',
    (string)($payload['room_number'] ?? ''),
    strtoupper((string)($payload['direction'] ?? '')),
    (string)($payload['location'] ?? ''),
    (string)($payload['date'] ?? ''),
    (string)($payload['time'] ?? '')
  );

  $baseBody = [
    'sender' => $sender,
    'recipient' => $to,
    'message' => $message,
    'options' => [
      'dryRun' => false,
      'failOnMultipleMessages' => false,
    ],
  ];

  $attempts = [];
  if ($authMode === 'bearer') {
    $attempts[] = ['mode' => 'bearer', 'headers' => ['Authorization: Bearer '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
  } elseif ($authMode === 'x-api-key') {
    $attempts[] = ['mode' => 'x-api-key', 'headers' => ['X-API-Key: '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
  } elseif ($authMode === 'apikey') {
    $attempts[] = ['mode' => 'apikey', 'headers' => ['apikey: '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
  } elseif ($authMode === 'query') {
    $sep = (strpos($endpoint, '?') !== false) ? '&' : '?';
    $attempts[] = ['mode' => 'query', 'headers' => [], 'url' => $endpoint . $sep . 'apiKey=' . rawurlencode($apiKey), 'body' => $baseBody];
  } else {
    $attempts[] = ['mode' => 'bearer', 'headers' => ['Authorization: Bearer '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
    $attempts[] = ['mode' => 'x-api-key', 'headers' => ['X-API-Key: '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
    $attempts[] = ['mode' => 'apikey', 'headers' => ['apikey: '.$apiKey], 'url' => $endpoint, 'body' => $baseBody];
    $sep = (strpos($endpoint, '?') !== false) ? '&' : '?';
    $attempts[] = ['mode' => 'query', 'headers' => [], 'url' => $endpoint . $sep . 'apiKey=' . rawurlencode($apiKey), 'body' => $baseBody];
  }

  $lastErr = 'SMS provider auth fallita';
  foreach ($attempts as $a) {
    $body = json_encode($a['body'], JSON_UNESCAPED_UNICODE);
    $headers = array_merge(['Content-Type: application/json'], $a['headers']);

    $ch = curl_init($a['url']);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
      $err = curl_error($ch);
      curl_close($ch);
      $lastErr = 'SMS curl error: ' . $err;
      continue;
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
      return;
    }

    $lastErr = 'SMS provider HTTP ' . $status . ': ' . $resp . ' [endpoint=' . $a['url'] . ', auth_mode=' . $a['mode'] . ']';

    if ($status !== 401) {
      break;
    }
  }

  throw new RuntimeException($lastErr);
}
