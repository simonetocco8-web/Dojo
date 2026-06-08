<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

function internal_transfer_navettista_phones(PDO $pdo): array {
  $stmt = $pdo->prepare("
    SELECT telefono
    FROM users
    WHERE deleted_at IS NULL
      AND is_active = 1
      AND telefono <> ''
      AND FIND_IN_SET('Navettista', REPLACE(dipartimento, ' ', '')) > 0
  ");
  $stmt->execute();
  return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function send_due_internal_transfer_reminders(PDO $pdo, array $env): array {
  ensure_transfer_internal_details_columns($pdo);
  ensure_transfer_internal_sms_reminders_table($pdo);

  $stmt = $pdo->query("
    SELECT t.id, t.room_number, t.direction, t.location, t.people_count, t.note, t.when_at
    FROM transfers_internal t
    LEFT JOIN transfer_internal_sms_reminders r ON r.transfer_id = t.id AND r.sent_at IS NOT NULL
    WHERE t.deleted_at IS NULL
      AND t.when_at >= NOW()
      AND t.when_at <= DATE_ADD(NOW(), INTERVAL 10 MINUTE)
      AND r.transfer_id IS NULL
    ORDER BY t.when_at ASC, t.id ASC
  ");
  $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $recipients = internal_transfer_navettista_phones($pdo);
  $result = array('checked' => count($transfers), 'sent' => 0, 'errors' => array());

  foreach ($transfers as $transfer) {
    $whenAt = new DateTimeImmutable($transfer['when_at']);
    try {
      sms_send_internal_transfer($env, array(
        'label' => 'REMINDER',
        'room_number' => $transfer['room_number'],
        'direction' => $transfer['direction'],
        'location' => $transfer['location'],
        'date' => $whenAt->format('d/m/Y'),
        'time' => $whenAt->format('H:i'),
        'people_count' => $transfer['people_count'],
        'note' => $transfer['note'],
        'recipients' => $recipients,
      ));

      $mark = $pdo->prepare("\n        INSERT INTO transfer_internal_sms_reminders (transfer_id, sent_at, last_error)\n        VALUES (?, NOW(), NULL)\n        ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), last_error = NULL\n      ");
      $mark->execute([(int)$transfer['id']]);
      $result['sent']++;
    } catch (Throwable $e) {
      $err = $e->getMessage();
      $mark = $pdo->prepare("\n        INSERT INTO transfer_internal_sms_reminders (transfer_id, sent_at, last_error)\n        VALUES (?, NULL, ?)\n        ON DUPLICATE KEY UPDATE last_error = VALUES(last_error)\n      ");
      $mark->execute([(int)$transfer['id'], $err]);
      $result['errors'][] = 'Transfer #' . (int)$transfer['id'] . ': ' . $err;
      error_log('Internal transfer reminder SMS failed: transfer '.$transfer['id'].' '.$err);
    }
  }

  return $result;
}
