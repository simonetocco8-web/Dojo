<?php

function sms_send_internal_transfer($env, $payload) {
  $cfg = isset($env['sms']) && is_array($env['sms']) ? $env['sms'] : array();
  if (empty($cfg['enabled'])) {
    return;
  }

  $apiKey = isset($cfg['api_key']) ? (string)$cfg['api_key'] : '';
  $endpoint = isset($cfg['endpoint']) ? (string)$cfg['endpoint'] : 'https://sms.openapi.com/IT-messages';
  $to = isset($cfg['to']) ? (string)$cfg['to'] : '';
  $sender = isset($cfg['sender']) ? (string)$cfg['sender'] : 'Dojo';

  if ($apiKey === '' || $to === '') {
    throw new RuntimeException('Configurazione SMS incompleta (api_key/to).');
  }

  $endpoint = str_replace('://api.openapi.com/', '://sms.openapi.com/', $endpoint);

  $endpointCandidates = array($endpoint);
  if (strpos($endpoint, '/IT-messages') === false) {
    $endpointCandidates[] = 'https://sms.openapi.com/IT-messages';
  }
  if (strpos($endpoint, '/v1/sms/send') !== false) {
    $endpointCandidates[] = str_replace('/v1/sms/send', '/sms/send', $endpoint);
    $endpointCandidates[] = str_replace('/v1/sms/send', '/v1/messages', $endpoint);
  }

  $room = isset($payload['room_number']) ? (string)$payload['room_number'] : '';
  $direction = isset($payload['direction']) ? strtoupper((string)$payload['direction']) : '';
  $location = isset($payload['location']) ? (string)$payload['location'] : '';
  $date = isset($payload['date']) ? (string)$payload['date'] : '';
  $time = isset($payload['time']) ? (string)$payload['time'] : '';

  $message = sprintf('Nuovo transfer interno: Camera %s %s %s - Data %s Ora %s', $room, $direction, $location, $date, $time);

  $jsonBody = json_encode(array(
    'sender' => $sender,
    'recipient' => $to,
    'message' => $message,
    'options' => array('dryRun' => false, 'failOnMultipleMessages' => false),
  ), JSON_UNESCAPED_UNICODE);

  $multipartBody = array(
    'sender' => $sender,
    'recipient' => $to,
    'message' => $message,
    'dryRun' => 'false',
    'failOnMultipleMessages' => 'false',
  );

  $authAttempts = array();
  foreach ($endpointCandidates as $ep) {
    $authAttempts[] = array('mode' => 'x-api-key', 'headers' => array('X-API-Key: ' . $apiKey), 'url' => $ep);
    $authAttempts[] = array('mode' => 'apikey', 'headers' => array('apikey: ' . $apiKey), 'url' => $ep);
    $authAttempts[] = array('mode' => 'bearer', 'headers' => array('Authorization: Bearer ' . $apiKey), 'url' => $ep);
    $sep = (strpos($ep, '?') !== false) ? '&' : '?';
    $authAttempts[] = array('mode' => 'query', 'headers' => array(), 'url' => $ep . $sep . 'apiKey=' . rawurlencode($apiKey));
  }

  $contentModes = array('json', 'multipart');
  $lastErr = 'SMS provider auth fallita';

  foreach ($authAttempts as $attempt) {
    foreach ($contentModes as $contentMode) {
      $headers = $attempt['headers'];
      $postFields = null;

      if ($contentMode === 'json') {
        $headers = array_merge(array('Content-Type: application/json'), $headers);
        $postFields = $jsonBody;
      } else {
        // NON impostare Content-Type: curl aggiunge boundary multipart/form-data automaticamente
        $postFields = $multipartBody;
      }

      $ch = curl_init($attempt['url']);
      curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postFields,
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

      $lastErr = 'SMS provider HTTP ' . $status . ': ' . $resp . ' [endpoint=' . $attempt['url'] . ', auth_mode=' . $attempt['mode'] . ', content=' . $contentMode . ']';

      if ($status !== 400 && $status !== 401 && $status !== 404) {
        break 2;
      }
    }
  }

  throw new RuntimeException($lastErr);
}
