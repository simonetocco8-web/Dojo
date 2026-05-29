<?php

function sms_send_internal_transfer($env, $payload) {
  $cfg = isset($env['sms']) && is_array($env['sms']) ? $env['sms'] : array();
  if (empty($cfg['enabled'])) {
    return;
  }

  $token = isset($cfg['access_token']) ? (string)$cfg['access_token'] : '';
  if ($token === '' && isset($cfg['api_key'])) {
    // Compatibilità con la configurazione già presente: OpenAPI SMS v2 vuole un Bearer token.
    $token = (string)$cfg['api_key'];
  }

  $endpoint = isset($cfg['endpoint']) ? (string)$cfg['endpoint'] : 'https://sms.openapi.com/IT-messages';
  $to = isset($cfg['to']) ? (string)$cfg['to'] : '';
  $sender = isset($cfg['sender']) ? (string)$cfg['sender'] : 'Openapi';
  $dryRun = isset($cfg['dry_run']) ? (bool)$cfg['dry_run'] : false;
  $failOnMultipleMessages = isset($cfg['fail_on_multiple_messages']) ? (bool)$cfg['fail_on_multiple_messages'] : false;

  if ($token === '' || $to === '') {
    throw new RuntimeException('Configurazione SMS incompleta (access_token/to).');
  }

  $endpoint = str_replace('://api.openapi.com/', '://sms.openapi.com/', $endpoint);
  if (strpos($endpoint, '/IT-messages') === false) {
    $endpoint = 'https://sms.openapi.com/IT-messages';
  }

  $room = isset($payload['room_number']) ? (string)$payload['room_number'] : '';
  $direction = isset($payload['direction']) ? strtoupper((string)$payload['direction']) : '';
  $location = isset($payload['location']) ? (string)$payload['location'] : '';
  $date = isset($payload['date']) ? (string)$payload['date'] : '';
  $time = isset($payload['time']) ? (string)$payload['time'] : '';

  $message = sprintf('Nuovo transfer interno: Camera %s %s %s - Data %s Ora %s', $room, $direction, $location, $date, $time);

  $body = json_encode(array(
    'sender' => $sender,
    'recipient' => $to,
    'message' => $message,
    'options' => array(
      'dryRun' => $dryRun,
      'failOnMultipleMessages' => $failOnMultipleMessages,
    ),
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array(
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Bearer ' . $token,
    ),
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ));

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('SMS curl error: ' . $err);
  }

  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($status < 200 || $status >= 300) {
    throw new RuntimeException('SMS provider HTTP ' . $status . ': ' . $resp . ' [endpoint=' . $endpoint . ', auth=bearer, content=json]');
  }
}
