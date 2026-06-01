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
    'base_url' => $envValue('APP_BASE_URL', ''), // es. '/adminapp' se in sottocartella
    'session_name' => $envValue('APP_SESSION_NAME', 'ADMINAPPSESSID'),
    'session_lifetime' => $envInt('APP_SESSION_LIFETIME', 36000),
    'csrf_key' => $envValue('APP_CSRF_KEY', 'change-this-secret-key')
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
    'enabled' => $envBool('SMS_ENABLED', true),
    'provider' => $envValue('SMS_PROVIDER', 'openapi'),
    'access_token' => $envValue('SMS_ACCESS_TOKEN', ''),
    'endpoint' => $envValue('SMS_ENDPOINT', 'https://sms.openapi.com/IT-messages'),
    'to' => $envValue('SMS_TO', ''),
    'sender' => $envValue('SMS_SENDER', 'Dojo'),
    'dry_run' => $envBool('SMS_DRY_RUN', false),
    'fail_on_multiple_messages' => $envBool('SMS_FAIL_ON_MULTIPLE_MESSAGES', false),
    'auth_mode' => $envValue('SMS_AUTH_MODE', 'bearer') // OpenAPI SMS v2 usa Authorization: Bearer <token>
  ],

  'openai_chatkit' => [
    'api_key' => $envValue('OPENAI_API_KEY', ''), // imposta OPENAI_API_KEY sul server, non committare chiavi API
    'workflow_id' => $envValue('OPENAI_CHATKIT_WORKFLOW_ID', ''),
    'workflow_version' => $envValue('OPENAI_CHATKIT_WORKFLOW_VERSION', ''), // opzionale: lascia vuoto per usare l'ultima versione pubblicata/deployata
    'domain_public_key' => $envValue('OPENAI_CHATKIT_DOMAIN_PUBLIC_KEY', ''),
    'session_endpoint' => $envValue('OPENAI_CHATKIT_SESSION_ENDPOINT', 'https://api.openai.com/v1/chatkit/sessions')
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
