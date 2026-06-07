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

$rawBody = file_get_contents('php://input');
$requestData = json_decode((string)$rawBody, true);
if (!is_array($requestData)) {
  $requestData = $_POST;
}

$message = trim((string)($requestData['message'] ?? ''));
$previousResponseId = trim((string)($requestData['previous_response_id'] ?? ''));
if ($message === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Messaggio vuoto.']);
  exit;
}

$env = require __DIR__ . '/config/env.php';
$user = current_user();
$config = array_replace($env['openai_chatkit'] ?? [], $env['openai_chat'] ?? []);
$localEnvPath = __DIR__ . '/config/env.local.php';
if (is_file($localEnvPath)) {
  $localEnv = require $localEnvPath;
  if (is_array($localEnv)) {
    $localConfig = array_replace($localEnv['openai_chatkit'] ?? [], $localEnv['openai_chat'] ?? []);
    if ($localConfig) {
      $config = array_replace($config, $localConfig);
    }
  }
}

$apiKey = trim((string)(
  getenv('OPENAI_API_KEY')
  ?: ($_SERVER['OPENAI_API_KEY'] ?? '')
  ?: ($_ENV['OPENAI_API_KEY'] ?? '')
  ?: ($config['api_key'] ?? '')
));
$promptId = trim((string)(
  getenv('OPENAI_PROMPT_ID')
  ?: ($_SERVER['OPENAI_PROMPT_ID'] ?? '')
  ?: ($_ENV['OPENAI_PROMPT_ID'] ?? '')
  ?: ($config['prompt_id'] ?? '')
));
$promptVersion = trim((string)(
  getenv('OPENAI_PROMPT_VERSION')
  ?: ($_SERVER['OPENAI_PROMPT_VERSION'] ?? '')
  ?: ($_ENV['OPENAI_PROMPT_VERSION'] ?? '')
  ?: ($config['prompt_version'] ?? '')
));
$endpoint = trim((string)($config['responses_endpoint'] ?? 'https://api.openai.com/v1/responses'));

if ($apiKey === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Configurazione AI Chat incompleta: imposta OPENAI_API_KEY sul server oppure config/env.local.php.']);
  exit;
}

if ($promptId === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Configurazione AI Chat incompleta: prompt_id mancante.']);
  exit;
}

$prompt = [
  'id' => $promptId,
  'variables' => [
    'dojo_user_id' => (string)(int)($user['id'] ?? 0),
    'dojo_user_email' => (string)($user['email'] ?? ''),
    'dojo_user_name' => trim((string)($user['nome'] ?? '') . ' ' . (string)($user['cognome'] ?? '')),
  ],
];
if ($promptVersion !== '') {
  $prompt['version'] = $promptVersion;
}

$payload = [
  'prompt' => $prompt,
  'input' => $message,
  'store' => true,
  'metadata' => [
    'dojo_user_id' => (string)(int)($user['id'] ?? 0),
  ],
];
if ($previousResponseId !== '') {
  $payload['previous_response_id'] = $previousResponseId;
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 45,
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

function ai_chat_extract_output_text(array $response): string {
  if (isset($response['output_text']) && is_string($response['output_text']) && trim($response['output_text']) !== '') {
    return trim($response['output_text']);
  }

  $parts = [];
  foreach (($response['output'] ?? []) as $item) {
    if (!is_array($item)) continue;
    foreach (($item['content'] ?? []) as $content) {
      if (!is_array($content)) continue;
      if (isset($content['text']) && is_string($content['text'])) {
        $parts[] = $content['text'];
      }
    }
  }

  return trim(implode("\n", $parts));
}

$reply = is_array($decoded) ? ai_chat_extract_output_text($decoded) : '';
if ($reply === '') {
  http_response_code(502);
  echo json_encode(['error' => 'Risposta OpenAI senza testo.']);
  exit;
}

echo json_encode([
  'response_id' => $decoded['id'] ?? null,
  'message' => $reply,
]);
