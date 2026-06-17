#!/usr/bin/php
<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../notification/send-notification-chat.php';
require_once __DIR__ . '/../core/task_sms_reminders.php';

// Imposta timezone locale (coerente con l’app)
date_default_timezone_set('Europe/Rome');

$env = require __DIR__ . '/../config/env.php';
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM `tasks` WHERE DATE(due_date) = CURDATE() AND deleted_at IS NULL;');
$stmt->execute();
$arrayTaskDiOggi = $stmt->fetchAll();

foreach ($arrayTaskDiOggi as $task) {
    notify($task['title'], $task['dipartimento'], $task['due_date'], $task['priority'], $task['description']);
}

try {
    send_due_task_sms_reminders($pdo, $env);
} catch (Throwable $e) {
    error_log('Task SMS cron failed: ' . $e->getMessage());
}
