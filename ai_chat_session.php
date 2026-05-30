<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';

require_login();
start_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Metodo non consentito']);
  exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['error' => 'Token CSRF non valido']);
  exit;
}

$env = require __DIR__ . '/config/env.php';
$user = current_user();
$config = $env['openai_chatkit'] ?? [];
$localEnvPath = __DIR__ . '/config/env.local.php';
if (is_file($localEnvPath)) {
  $localEnv = require $localEnvPath;
  if (is_array($localEnv) && isset($localEnv['openai_chatkit']) && is_array($localEnv['openai_chatkit'])) {
    $config = array_replace($config, $localEnv['openai_chatkit']);
  }
}
$apiKey = trim((string)(
  getenv('OPENAI_API_KEY')
  ?: ($_SERVER['OPENAI_API_KEY'] ?? '')
  ?: ($_ENV['OPENAI_API_KEY'] ?? '')
  ?: ($config['api_key'] ?? '')
));
$workflowId = trim((string)($config['workflow_id'] ?? ''));
$endpoint = trim((string)($config['session_endpoint'] ?? 'https://api.openai.com/v1/chatkit/sessions'));

if ($apiKey === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Configurazione AI Chat incompleta: imposta OPENAI_API_KEY sul server oppure config/env.local.php.']);
  exit;
}

if ($workflowId === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Configurazione AI Chat incompleta: workflow_id mancante.']);
  exit;
}

$payload = [
  'workflow' => [
    'id' => $workflowId,
    'state_variables' => [
      'dojo_user_id' => (int)($user['id'] ?? 0),
      'dojo_user_email' => (string)($user['email'] ?? ''),
      'dojo_user_name' => trim((string)($user['nome'] ?? '') . ' ' . (string)($user['cognome'] ?? '')),
    ],
  ],
  'user' => 'dojo-user-' . (int)($user['id'] ?? 0),
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
    'OpenAI-Beta: chatkit_beta=v1',
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 30,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
  http_response_code(502);
  echo json_encode(['error' => 'Errore connessione OpenAI: ' . $curlError]);
  exit;
}

$decoded = json_decode((string)$response, true);
if ($httpCode < 200 || $httpCode >= 300) {
  $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error'] ?? 'Errore OpenAI') : 'Errore OpenAI';
  http_response_code($httpCode ?: 502);
  echo json_encode(['error' => $message]);
  exit;
}

$clientSecret = is_array($decoded) ? ($decoded['client_secret'] ?? '') : '';
if ($clientSecret === '') {
  http_response_code(502);
  echo json_encode(['error' => 'Risposta OpenAI senza client_secret']);
  exit;
}

echo json_encode(['client_secret' => $clientSecret]);
