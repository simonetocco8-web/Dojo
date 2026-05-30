<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/transfer_reminders.php';

$env = require __DIR__ . '/config/env.php';
$pdo = db();

$result = send_due_internal_transfer_reminders($pdo, $env);

if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
