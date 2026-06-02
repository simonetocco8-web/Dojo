<?php
require_once __DIR__ . '/google-api-client/vendor/autoload.php';
$env = require __DIR__ . '/../config/env.php';

$client = new Google\Client();
$client->setAuthConfig($env['google']['oauth_secret_json']);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setScopes([Google\Service\Calendar::CALENDAR]);
$client->setIncludeGrantedScopes(true);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim((string)($env['app']['base_url'] ?? ''), '/');
$redirectUri = $scheme . '://' . $host . $base . '/google/oauth2callback.php';
$client->setRedirectUri($redirectUri);

if (!isset($_GET['code'])) { http_response_code(400); echo 'Missing code'; exit; }

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) { echo 'OAuth error: '.htmlspecialchars($token['error']); exit; }

file_put_contents($env['google']['oauth_token_json'], json_encode($token));
echo 'Google Calendar collegato! Ora puoi creare transfer e verranno aggiunti al calendario.';
