<?php
require_once '/home/bwlxtuul/dojo.villaggiotramonto.it/google/google-api-client/vendor/autoload.php';
$env = require  '/home/bwlxtuul/dojo.villaggiotramonto.it/config/env.php';

$client = new Google\Client();
$client->setAuthConfig($env['google']['oauth_secret_json']);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setScopes([Google\Service\Calendar::CALENDAR]);
$client->setRedirectUri('https://dojo.villaggiotramonto.it/google/oauth2callback.php');
header('Location: '.$client->createAuthUrl());
