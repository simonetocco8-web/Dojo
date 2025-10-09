<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/ewelink.php';

require_login();
require_admin();
start_session();

$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

if (!ewelink_is_configured()) {
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_config');
    exit;
}

$expectedState = $_SESSION['ewelink_oauth_state'] ?? null;
unset($_SESSION['ewelink_oauth_state']);
$state = $_GET['state'] ?? '';

if (!$expectedState || !$state || !hash_equals($expectedState, $state)) {
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_state');
    exit;
}

if (isset($_GET['error'])) {
    $message = $_GET['error_description'] ?? $_GET['error'] ?? 'Autorizzazione negata.';
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_error&message=' . urlencode($message));
    exit;
}

$code = $_GET['code'] ?? null;
if (!$code) {
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_error&message=' . urlencode('Codice di autorizzazione mancante.'));
    exit;
}

try {
    $tokens = ewelink_exchange_code($code);
    ewelink_store_tokens((int)$user['id'], $tokens);
    header('Location: ' . $base . '/ewelink/devices.php?status=connected');
    exit;
} catch (Throwable $e) {
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_error&message=' . urlencode($e->getMessage()));
    exit;
}
