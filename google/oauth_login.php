<?php
require_once __DIR__ . '/google-api-client/vendor/autoload.php';
$env = require __DIR__ . '/../config/env.php';

$client = new Google\Client();
$client->setAuthConfig($env['google']['oauth_secret_json']);
$client->setAccessType('offline');
$client->setPrompt('consent select_account');
$client->setScopes([Google\Service\Calendar::CALENDAR]);
$client->setIncludeGrantedScopes(true);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim((string)($env['app']['base_url'] ?? ''), '/');
$redirectUri = $scheme . '://' . $host . $base . '/google/oauth2callback.php';
$client->setRedirectUri($redirectUri);
header('Location: '.$client->createAuthUrl());
