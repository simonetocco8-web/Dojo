<?php

function sms_send_message($env, $recipients, $message) {
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
  $sender = isset($cfg['sender']) ? (string)$cfg['sender'] : 'Dojo';
  $dryRun = isset($cfg['dry_run']) ? (bool)$cfg['dry_run'] : false;
  $failOnMultipleMessages = isset($cfg['fail_on_multiple_messages']) ? (bool)$cfg['fail_on_multiple_messages'] : false;

  if ($token === '') {
    throw new RuntimeException('Configurazione SMS incompleta (access_token).');
  }

  $endpoint = str_replace('://api.openapi.com/', '://sms.openapi.com/', $endpoint);
  if (strpos($endpoint, '/IT-messages') === false) {
    $endpoint = 'https://sms.openapi.com/IT-messages';
  }

  if (!is_array($recipients)) {
    $recipients = array($recipients);
  }
  $recipients = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $recipients)))));
  if (!$recipients) {
    throw new RuntimeException('Nessun destinatario SMS configurato.');
  }

  $message = trim((string)$message);
  if ($message === '') {
    throw new RuntimeException('Corpo SMS vuoto.');
  }
  if ((function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message)) > 160) {
    throw new RuntimeException('Il corpo SMS supera i 160 caratteri.');
  }

  $errors = array();
  foreach ($recipients as $recipient) {
    $body = json_encode(array(
      'sender' => $sender,
      'recipient' => $recipient,
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
      $errors[] = $recipient . ': SMS curl error: ' . curl_error($ch);
      curl_close($ch);
      continue;
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
      $errors[] = $recipient . ': SMS provider HTTP ' . $status . ': ' . $resp . ' [endpoint=' . $endpoint . ', auth=bearer, content=json]';
    }
  }

  if ($errors) {
    throw new RuntimeException(implode(' ; ', $errors));
  }
}


function sms_utf8_length(string $value): int {
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function sms_utf8_substr(string $value, int $start, int $length): string {
  return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function sms_append_optional_note(string $message, string $note, int $maxLength = 160): string {
  $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
  if ($note === '') {
    return sms_utf8_length($message) > $maxLength ? sms_utf8_substr($message, 0, $maxLength) : $message;
  }

  $prefix = ' - Note ';
  $remaining = $maxLength - sms_utf8_length($message) - sms_utf8_length($prefix);
  if ($remaining <= 0) {
    return sms_utf8_substr($message, 0, $maxLength);
  }

  if (sms_utf8_length($note) > $remaining) {
    $ellipsis = '…';
    $note = sms_utf8_substr($note, 0, max(0, $remaining - sms_utf8_length($ellipsis))) . $ellipsis;
  }

  return $message . $prefix . $note;
}

function sms_send_internal_transfer($env, $payload) {
  $cfg = isset($env['sms']) && is_array($env['sms']) ? $env['sms'] : array();
  $fallbackTo = isset($cfg['to']) ? (string)$cfg['to'] : '';

  $room = isset($payload['room_number']) ? (string)$payload['room_number'] : '';
  $direction = isset($payload['direction']) ? strtoupper((string)$payload['direction']) : '';
  $location = isset($payload['location']) ? (string)$payload['location'] : '';
  $date = isset($payload['date']) ? (string)$payload['date'] : '';
  $time = isset($payload['time']) ? (string)$payload['time'] : '';
  $peopleCount = isset($payload['people_count']) ? trim((string)$payload['people_count']) : '';
  $note = isset($payload['note']) ? (string)$payload['note'] : '';
  $recipients = array();
  if (isset($payload['recipients']) && is_array($payload['recipients'])) {
    $recipients = $payload['recipients'];
  } elseif (isset($payload['recipient'])) {
    $recipients = array($payload['recipient']);
  } elseif ($fallbackTo !== '') {
    $recipients = array($fallbackTo);
  }

  $label = isset($payload['label']) ? trim((string)$payload['label']) : 'Nuovo';
  if ($label === '') $label = 'Nuovo';
  $peoplePart = $peopleCount !== '' ? ' - Persone ' . $peopleCount : '';
  $message = sprintf('%s transfer interno: Camera %s %s %s - Data %s Ora %s%s', $label, $room, $direction, $location, $date, $time, $peoplePart);
  $message = sms_append_optional_note($message, $note);
  sms_send_message($env, $recipients, $message);
}
