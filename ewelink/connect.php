<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/ewelink.php';

require_login();
require_admin();
start_session();

if (!ewelink_is_configured()) {
    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
    echo '<h1>Configurazione mancante</h1><p>Completa i parametri OAuth eWeLink in config/env.php.</p>';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['ewelink_oauth_state'] = $state;

$authUrl = ewelink_authorization_url($state);
header('Location: ' . $authUrl);
exit;
