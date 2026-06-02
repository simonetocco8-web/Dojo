<?php
// config/env.php
// I segreti non devono essere committati: impostali come variabili d'ambiente sul server.
$envValue = static function (string $key, string $default = ''): string {
  $value = getenv($key);
  return $value === false ? $default : $value;
};
$envBool = static function (string $key, bool $default = false) use ($envValue): bool {
  $value = $envValue($key, $default ? '1' : '0');
  return filter_var($value, FILTER_VALIDATE_BOOLEAN);
};
$envInt = static function (string $key, int $default = 0) use ($envValue): int {
  $value = $envValue($key, (string)$default);
  return is_numeric($value) ? (int)$value : $default;
};

return [
  'db' => [
    'host' => $envValue('DB_HOST', '127.0.0.1'),
    'port' => $envInt('DB_PORT', 3306),
    'name' => $envValue('DB_NAME', ''),
    'user' => $envValue('DB_USER', ''),
    'pass' => $envValue('DB_PASS', ''),
    'charset' => $envValue('DB_CHARSET', 'utf8mb4')
  ],
  'app' => [
    'base_url' => '', // es. '/adminapp' se in sottocartella
    'session_name' => 'ADMINAPPSESSID',
    'session_lifetime' => 36000,
    'csrf_key' => 'change-this-secret-key'
  ],
  'mail' => [
    'from' => $envValue('MAIL_FROM', 'dojo@villaggiotramonto.it'),
    'from_name' => $envValue('MAIL_FROM_NAME', 'Dojo Villaggio Tramonto'),
    'smtp' => $envBool('MAIL_SMTP', false),
    'smtp_host' => $envValue('MAIL_SMTP_HOST', ''),
    'smtp_port' => $envInt('MAIL_SMTP_PORT', 587),
    'smtp_user' => $envValue('MAIL_SMTP_USER', ''),
    'smtp_pass' => $envValue('MAIL_SMTP_PASS', '')
  ],
  'ewelink' => [
    'client_id' => $envValue('EWELINK_CLIENT_ID', ''),
    'client_secret' => $envValue('EWELINK_CLIENT_SECRET', ''),
    // URL pubblico verso ewelink/callback.php
    'redirect_uri' => $envValue('EWELINK_REDIRECT_URI', ''),
    // Endpoint di default (puoi sovrascriverli se usi una regione diversa)
    'auth_base' => $envValue('EWELINK_AUTH_BASE', 'https://eu-apia.coolkit.cc'),
    'api_base' => $envValue('EWELINK_API_BASE', 'https://eu-apia.coolkit.cc'),
    // Scopes consigliati: device lettura/scrittura
    'scope' => $envValue('EWELINK_SCOPE', 'userinfo:read device:read device:write')
  ],
  'sms' => [
    'enabled' => true,
    'provider' => 'openapi',
    'access_token' => '6a185e25847aaa113b0959c6',
    'endpoint' => 'https://sms.openapi.com/IT-messages',
    'to' => '+393341913800',
    'sender' => 'Dojo',
    'dry_run' => false,
    'fail_on_multiple_messages' => false,
    'auth_mode' => 'bearer' // OpenAPI SMS v2 usa Authorization: Bearer <token>
  ],

  'openai_chatkit' => [
    'api_key' => getenv('OPENAI_API_KEY') ?: '', // imposta OPENAI_API_KEY sul server, non committare chiavi API
    'workflow_id' => 'wf_6a1ab0fd7a6881908bab573ddb7a682e06c24f25188285f0',
    'workflow_version' => getenv('OPENAI_CHATKIT_WORKFLOW_VERSION') ?: '', // opzionale: lascia vuoto per usare l'ultima versione pubblicata/deployata
    'domain_public_key' => 'domain_pk_6a1ada350d5481909dee4821f97c99250215095942c27d8b',
    'session_endpoint' => 'https://api.openai.com/v1/chatkit/sessions'
  ],

  'google' => [
    // A) OAuth UTENTE con file credentials.json (consigliata se già lo usi)
    'oauth_secret_json' => $envValue('GOOGLE_OAUTH_SECRET_JSON', __DIR__ . '/../google/google_client_secret.json'),
    'oauth_token_json' => $envValue('GOOGLE_OAUTH_TOKEN_JSON', __DIR__ . '/../google/google_token.json'),
    'riassetti_calendar_id' => $envValue('GOOGLE_RIASSETTI_CALENDAR_ID', ''),
    'calendar_id' => $envValue('GOOGLE_TRANSFERS_CALENDAR_ID', ''),
    'calendar_days_off_id' => $envValue('GOOGLE_DAYS_OFF_CALENDAR_ID', '')
  ]
];
