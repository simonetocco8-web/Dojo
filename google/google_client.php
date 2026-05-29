<?php
require_once __DIR__ . '/google-api-client/vendor/autoload.php';

function google_calendar_client(): Google\Service\Calendar {
  $env = require __DIR__ . '/../config/env.php';
  $client = new Google\Client();
  $client->setAuthConfig($env['google']['oauth_secret_json']);
  $client->setAccessType('offline');
  $client->setScopes([Google\Service\Calendar::CALENDAR]);
  $tokenPath = $env['google']['oauth_token_json'];

  if (!file_exists($tokenPath)) {
    throw new RuntimeException('Token OAuth mancante. Visita /google/oauth_login.php per collegare il calendario.');
  }
  $accessToken = json_decode(file_get_contents($tokenPath), true);
  $client->setAccessToken($accessToken);

  if ($client->isAccessTokenExpired()) {
    $refreshToken = $client->getRefreshToken();
    if (!$refreshToken) {
      throw new RuntimeException('Token Google scaduto e refresh_token assente. Ricollega Google da /google/oauth_login.php.');
    }

    $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
    if (isset($newToken['error'])) {
      $err = (string)($newToken['error'] ?? '');
      $desc = (string)($newToken['error_description'] ?? '');
      if ($err === 'invalid_grant') {
        throw new RuntimeException("Connessione Google scaduta/revocata (invalid_grant). Ricollega l'account da /google/oauth_login.php.");
      }
      throw new RuntimeException('Refresh token Google fallito: ' . trim($err . ' ' . $desc));
    }

    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
  }
  return new Google\Service\Calendar($client);
}
