<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/ewelink.php';

require_login();
require_admin();

$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

if (!isset($_POST['csrf']) || !csrf_check($_POST['csrf'])) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
    echo 'Token CSRF non valido.';
    exit;
}

if (!ewelink_is_configured()) {
    header('Location: ' . $base . '/ewelink/devices.php?status=oauth_config');
    exit;
}

$deviceId = trim($_POST['device_id'] ?? '');
$command = $_POST['command'] ?? '';

$params = [];
if ($command === 'turn_on') {
    $params = ['switch' => 'on'];
} elseif ($command === 'turn_off') {
    $params = ['switch' => 'off'];
} else {
    header('Location: ' . $base . '/ewelink/devices.php?status=control_error&message=' . urlencode('Comando non riconosciuto.'));
    exit;
}

try {
    ewelink_control_device((int)$user['id'], $deviceId, $params);
    header('Location: ' . $base . '/ewelink/devices.php?status=control_ok');
    exit;
} catch (Throwable $e) {
    header('Location: ' . $base . '/ewelink/devices.php?status=control_error&message=' . urlencode($e->getMessage()));
    exit;
}
