<?php
require_once __DIR__ . '/core/auth.php';
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
logout();
header('Location: ' . $base . '/index.php');
exit;
