<?php

function sms_send_internal_transfer(array $env, array $payload): void {
  $cfg = $env['sms'] ?? [];
  if (empty($cfg['enabled'])) return;

  $apiKey = (string)($cfg['api_key'] ?? '');
  $endpoint = (string)($cfg['endpoint'] ?? '');
  $to = (string)($cfg['to'] ?? '');

  if ($apiKey === '' || $endpoint === '' || $to === '') {
    throw new RuntimeException('Configurazione SMS incompleta (api_key/endpoint/to).');
  }

  $message = sprintf(
    "Nuovo transfer interno: Camera %s %s %s - Data %s Ora %s",
    (string)($payload['room_number'] ?? ''),
    strtoupper((string)($payload['direction'] ?? '')),
    (string)($payload['location'] ?? ''),
    (string)($payload['date'] ?? ''),
    (string)($payload['time'] ?? '')
  );

  $body = json_encode([
    'to' => $to,
    'message' => $message,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 15,
  ]);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('SMS curl error: ' . $err);
  }

  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($status < 200 || $status >= 300) {
    throw new RuntimeException('SMS provider HTTP ' . $status . ': ' . $resp);
  }
}
