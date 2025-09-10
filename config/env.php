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
  ]
];
