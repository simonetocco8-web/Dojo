<?php
require_once __DIR__ . '/google_client.php';

function gcal_sync_riassetto(PDO $pdo, int $riassettoId): ?string {
  $env = require __DIR__ . '/../config/env.php';
  $calendarId = $env['google']['riassetti_calendar_id'] ?? null;
  if (!$calendarId) {
    return null;
  }

  $st = $pdo->prepare('SELECT * FROM riassetti WHERE id = ? LIMIT 1');
  $st->execute([$riassettoId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new RuntimeException('Riassetto non trovato');
  }

  $service = google_calendar_client();

  $linenParts = [];
  if (!empty($row['qty_matrimoniale'])) {
    $linenParts[] = $row['qty_matrimoniale'] . ' Matrimoniale';
  }
  if (!empty($row['qty_singola'])) {
    $linenParts[] = $row['qty_singola'] . ' Singola';
  }
  if (!empty($row['qty_set_bagno'])) {
    $linenParts[] = $row['qty_set_bagno'] . ' Set Bagno';
  }
  $linenSummary = $linenParts ? implode(', ', $linenParts) : 'Nessuna biancheria specificata';

  $descriptionLines = [
    'Biancheria: ' . $linenSummary,
    'Pulizia extra: ' . (!empty($row['pulizia_extra']) ? 'Sì' : 'No'),
  ];
  if (!empty($row['note'])) {
    $descriptionLines[] = 'Note: ' . $row['note'];
  }
  $description = implode("\n", $descriptionLines);

  $date = $row['data_riassetto'];
  $start = new DateTimeImmutable($date, new DateTimeZone('Europe/Rome'));
  $end = $start->modify('+1 day');

  $eventData = [
    'summary' => 'Riassetto Camera ' . $row['room'],
    'description' => $description,
    'start' => [
      'date' => $start->format('Y-m-d'),
      'timeZone' => 'Europe/Rome',
    ],
    'end' => [
      'date' => $end->format('Y-m-d'),
      'timeZone' => 'Europe/Rome',
    ],
  ];

  $eventId = $row['google_event_id'] ?? null;
  try {
    if ($eventId) {
      $event = $service->events->get($calendarId, $eventId);
      foreach ($eventData as $key => $value) {
        $event->$key = $value;
      }
      $updated = $service->events->update($calendarId, $eventId, $event);
      $eventId = $updated->getId();
    } else {
      $event = new Google\Service\Calendar\Event($eventData);
      $created = $service->events->insert($calendarId, $event);
      $eventId = $created->getId();
    }
  } catch (Google\Service\Exception $e) {
    error_log('Errore sincronizzazione Google Calendar riassetti: ' . $e->getMessage());
    return null;
  }

  if ($eventId !== ($row['google_event_id'] ?? null)) {
    $upd = $pdo->prepare('UPDATE riassetti SET google_event_id = ? WHERE id = ?');
    $upd->execute([$eventId, $riassettoId]);
  }

  return $eventId;
}

function gcal_delete_riassetto_event(PDO $pdo, int $riassettoId): void {
  $env = require __DIR__ . '/../config/env.php';
  $calendarId = $env['google']['riassetti_calendar_id'] ?? null;
  if (!$calendarId) {
    return;
  }

  $st = $pdo->prepare('SELECT google_event_id FROM riassetti WHERE id = ? LIMIT 1');
  $st->execute([$riassettoId]);
  $eventId = $st->fetchColumn();
  if (!$eventId) {
    return;
  }

  $service = google_calendar_client();
  try {
    $service->events->delete($calendarId, $eventId);
  } catch (Google\Service\Exception $e) {
    error_log('Errore eliminazione evento riassetto: ' . $e->getMessage());
  }

  $upd = $pdo->prepare('UPDATE riassetti SET google_event_id = NULL WHERE id = ?');
  $upd->execute([$riassettoId]);
}
