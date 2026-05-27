<?php

function sms_send_internal_transfer($env, $payload) {
  $cfg = isset($env['sms']) && is_array($env['sms']) ? $env['sms'] : array();
  if (empty($cfg['enabled'])) {
    return;
  }

  $apiKey = isset($cfg['api_key']) ? (string)$cfg['api_key'] : '';
  $endpoint = isset($cfg['endpoint']) ? (string)$cfg['endpoint'] : 'https://sms.openapi.com/v1/sms/send';
  $to = isset($cfg['to']) ? (string)$cfg['to'] : '';
  $sender = isset($cfg['sender']) ? (string)$cfg['sender'] : 'Dojo';

  if ($apiKey === '' || $to === '') {
    throw new RuntimeException('Configurazione SMS incompleta (api_key/to).');
  }

  $endpoint = str_replace('://api.openapi.com/', '://sms.openapi.com/', $endpoint);

  $room = isset($payload['room_number']) ? (string)$payload['room_number'] : '';
  $direction = isset($payload['direction']) ? strtoupper((string)$payload['direction']) : '';
  $location = isset($payload['location']) ? (string)$payload['location'] : '';
  $date = isset($payload['date']) ? (string)$payload['date'] : '';
  $time = isset($payload['time']) ? (string)$payload['time'] : '';

  $message = sprintf(
    'Nuovo transfer interno: Camera %s %s %s - Data %s Ora %s',
    $room,
    $direction,
    $location,
    $date,
    $time
  );

  $bodyPayload = array(
    'sender' => $sender,
    'recipient' => $to,
    'message' => $message,
    'options' => array(
      'dryRun' => false,
      'failOnMultipleMessages' => false,
    ),
  );

  $authHeaders = array(
    array('mode' => 'bearer', 'headers' => array('Authorization: Bearer ' . $apiKey), 'url' => $endpoint),
    array('mode' => 'x-api-key', 'headers' => array('X-API-Key: ' . $apiKey), 'url' => $endpoint),
    array('mode' => 'apikey', 'headers' => array('apikey: ' . $apiKey), 'url' => $endpoint),
  );

  $sep = (strpos($endpoint, '?') !== false) ? '&' : '?';
  $authHeaders[] = array('mode' => 'query', 'headers' => array(), 'url' => $endpoint . $sep . 'apiKey=' . rawurlencode($apiKey));

  $lastErr = 'SMS provider auth fallita';

  foreach ($authHeaders as $attempt) {
    $body = json_encode($bodyPayload, JSON_UNESCAPED_UNICODE);
    $headers = array_merge(array('Content-Type: application/json'), $attempt['headers']);

    $ch = curl_init($attempt['url']);
    curl_setopt_array($ch, array(
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 20,
    ));

    $resp = curl_exec($ch);
    if ($resp === false) {
      $lastErr = 'SMS curl error: ' . curl_error($ch);
      curl_close($ch);
      continue;
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
      return;
    }

    $lastErr = 'SMS provider HTTP ' . $status . ': ' . $resp . ' [endpoint=' . $attempt['url'] . ', auth_mode=' . $attempt['mode'] . ']';
    if ($status !== 401) {
      break;
    }
  }

  throw new RuntimeException($lastErr);
}
