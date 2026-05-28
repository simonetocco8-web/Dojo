<?php
// config/env.php
return [
  'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'bwlxtuul_dojo',
    'user' => 'bwlxtuul_dojo',
    'pass' => 'Wso-T{^L,V4D;!A(',
    'charset' => 'utf8mb4'
  ],
  'app' => [
    'base_url' => '', // es. '/adminapp' se in sottocartella
    'session_name' => 'ADMINAPPSESSID',
    'session_lifetime' => 3600,
    'csrf_key' => 'change-this-secret-key'
  ],
  'mail' => [
    'from' => 'dojo@villaggiotramonto.it',
    'from_name' => 'Dojo Villaggio Tramonto',
    'smtp' => false,
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => ''
  ],
  'ewelink' => [
    'client_id' => 'mycTWeG1Fm3hO2iYW3QoOjbmmCiULMsQ',
    'client_secret' => 'y4SwpC6cFcvEG5Kp3LMHP9JD2NXJxKGk',
    // URL pubblico verso ewelink/callback.php
    'redirect_uri' => '',
    // Endpoint di default (puoi sovrascriverli se usi una regione diversa)
    'auth_base' => 'https://eu-apia.coolkit.cc',
    'api_base' => 'https://eu-apia.coolkit.cc',
    // Scopes consigliati: device lettura/scrittura
    'scope' => 'userinfo:read device:read device:write'
  ],
  'sms' => [
    'enabled' => true,
    'provider' => 'openapi',
    'access_token' => 'haxaguwpis88ysvnbjfw8jhvr8rbqmbs',
    'endpoint' => 'https://sms.openapi.com/IT-messages',
    'to' => '+393341913800',
    'sender' => 'Dojo',
    'auth_mode' => 'bearer' // OpenAPI SMS v2 usa Authorization: Bearer <token>
  ],

  'google' => [
    // A) OAuth UTENTE con file credentials.json (consigliata se già lo usi)
    'oauth_secret_json' => __DIR__ . '/../google/google_client_secret.json',
    'oauth_token_json' =>  __DIR__ . '/../google/google_token.json', // percorso reale al file scaricato da Google Cloud
    'riassetti_calendar_id' => '13cbf1c11d4c3501563e17c909423fabeb42b3e74a7869f0dbaf6cfb6d12779b@group.calendar.google.com',
    'calendar_id' => 'e52b190abb101e0368e78447f0a8857475f0f31163f07e635a357354fde3817d@group.calendar.google.com',
    'calendar_days_off_id' => 'f110e9509588ceae765a4cf687e66b0fc2865c0e9e33ed337ddbd2a933a8b358@group.calendar.google.com'
  ]
];
