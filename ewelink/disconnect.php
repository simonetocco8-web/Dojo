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

ewelink_delete_tokens((int)$user['id']);

header('Location: ' . $base . '/ewelink/devices.php?status=disconnected');
exit;
