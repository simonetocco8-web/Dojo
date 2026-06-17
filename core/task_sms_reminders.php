<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

function task_sms_timezone(): DateTimeZone {
  return new DateTimeZone('Europe/Rome');
}

function schedule_task_sms_reminder(PDO $pdo, int $taskId, array $recipients, string $message, string $dueDate): void {
  ensure_task_sms_reminders_table($pdo);

  $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueDate . ' 08:00:00', task_sms_timezone());
  if (!$scheduledAt || $scheduledAt->format('Y-m-d') !== $dueDate) {
    throw new InvalidArgumentException('Data SMS task non valida.');
  }

  $recipients = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $recipients)))));
  $payload = json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($payload === false) {
    throw new RuntimeException('Impossibile preparare i destinatari SMS del task.');
  }

  $stmt = $pdo->prepare("\n    INSERT INTO task_sms_reminders (task_id, recipients, message, scheduled_at, sent_at, last_error)\n    VALUES (?, ?, ?, ?, NULL, NULL)\n    ON DUPLICATE KEY UPDATE\n      recipients = VALUES(recipients),\n      message = VALUES(message),\n      scheduled_at = VALUES(scheduled_at),\n      sent_at = NULL,\n      last_error = NULL\n  ");
  $stmt->execute([$taskId, $payload, $message, $scheduledAt->format('Y-m-d H:i:s')]);
}

function send_due_task_sms_reminders(PDO $pdo, array $env, ?DateTimeInterface $now = null): array {
  ensure_task_sms_reminders_table($pdo);

  $tz = task_sms_timezone();
  $nowLocal = $now
    ? (new DateTimeImmutable('@' . $now->getTimestamp()))->setTimezone($tz)
    : new DateTimeImmutable('now', $tz);

  $stmt = $pdo->prepare("\n    SELECT r.id, r.task_id, r.recipients, r.message\n    FROM task_sms_reminders r\n    INNER JOIN tasks t ON t.id = r.task_id\n    WHERE r.sent_at IS NULL\n      AND r.scheduled_at <= ?\n      AND t.deleted_at IS NULL\n    ORDER BY r.scheduled_at ASC, r.id ASC\n  ");
  $stmt->execute([$nowLocal->format('Y-m-d H:i:s')]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
  foreach ($rows as $row) {
    $recipients = json_decode((string)$row['recipients'], true);
    if (!is_array($recipients)) {
      $recipients = [];
    }

    try {
      sms_send_message($env, $recipients, (string)$row['message']);
      $mark = $pdo->prepare('UPDATE task_sms_reminders SET sent_at = NOW(), last_error = NULL WHERE id = ?');
      $mark->execute([(int)$row['id']]);
      $result['sent']++;
    } catch (Throwable $e) {
      $error = $e->getMessage();
      $mark = $pdo->prepare('UPDATE task_sms_reminders SET last_error = ? WHERE id = ?');
      $mark->execute([$error, (int)$row['id']]);
      $result['failed']++;
      $result['errors'][] = ['task_id' => (int)$row['task_id'], 'error' => $error];
      error_log('Task reminder SMS failed: task ' . $row['task_id'] . ' ' . $error);
    }
  }

  return $result;
}
