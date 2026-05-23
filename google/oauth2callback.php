<?php
require_once '/home/bwlxtuul/dojo.villaggiotramonto.it/google/google-api-client/vendor/autoload.php';
$env = require  '/home/bwlxtuul/dojo.villaggiotramonto.it/config/env.php';

$client = new Google\Client();
$client->setAuthConfig($env['google']['oauth_secret_json']);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setScopes([Google\Service\Calendar::CALENDAR]);
$client->setRedirectUri('https://dojo.villaggiotramonto.it/google/oauth2callback.php');

if (!isset($_GET['code'])) { http_response_code(400); echo 'Missing code'; exit; }

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) { echo 'OAuth error: '.htmlspecialchars($token['error']); exit; }

file_put_contents($env['google']['oauth_token_json'], json_encode($token));
echo 'Google Calendar collegato! Ora puoi creare transfer e verranno aggiunti al calendario.';
