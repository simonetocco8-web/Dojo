<?php
require_once __DIR__ . '/google_client.php';


function gcal_try_insert_event(Google\Service\Calendar $service, string $calendarId, Google\Service\Calendar\Event $event): Google\Service\Calendar\Event {
  return $service->events->insert($calendarId, $event);
}

function gcal_create_event_for_internal_transfer(PDO $pdo, int $transferId): ?string {
  $env = require __DIR__ . '/../config/env.php';
  $googleCfg = $env['google'] ?? [];
  $calendarId = $googleCfg['calendar_id'] ?? 'primary';

  // 1) Leggi transfer  

  $st = $pdo->prepare("
    SELECT id, room_number, direction, location, when_at
    FROM transfers_internal
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$transferId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);
  if (!$t) throw new RuntimeException('Transfer non trovato');
  $freccia = '';
  if ($t['direction']=='da'){$freccia="←";}else{$freccia="→";}

  // 2) Costruisci titolo e descrizione
  $loc = $t['location'];
  $titolo = sprintf("Transfer Camera %s %s ".$freccia." %s",
                    $t['room_number'],
                    ($t['direction']==='da'?'Da':'Per'),
                    $loc);
  
 

  // 3) Start/End (default 30 minuti)
  $tz = new DateTimeZone('Europe/Rome');

  if (empty($t['when_at'])) {
    throw new RuntimeException('Data/Ora non valide (when_at vuoto).');
  }

  $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$t['when_at'], $tz);
  if (!$startDt) {
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', (string)$t['when_at'], $tz);
  }
  if (!$startDt) {
    throw new RuntimeException('Data/Ora non valide per Google Calendar: ' . (string)$t['when_at']);
  }
  $endDt = $startDt->modify('+30 minutes');

  // 4) Crea evento
  $service = google_calendar_client();
  $event = new Google\Service\Calendar\Event([
    'summary'     => $titolo,
    'start' => [
      'dateTime' => $startDt->format(DateTime::RFC3339),
      'timeZone' => 'Europe/Rome',
    ],
    'end' => [
      'dateTime' => $endDt->format(DateTime::RFC3339),
      'timeZone' => 'Europe/Rome',
    ],
   
    'reminders' => [
    'useDefault' => false,
    'overrides' => [
      ['method' => 'popup', 'minutes' => 30],
      ['method' => 'popup', 'minutes' => 10],
    ],
  ],
  ]);

  try {
    $created = gcal_try_insert_event($service, $calendarId, $event);
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    $canFallback = ($calendarId !== 'primary') && (stripos($msg, 'Not Found') !== false || stripos($msg, 'notFound') !== false);
    if (!$canFallback) {
      throw $e;
    }
    $created = gcal_try_insert_event($service, 'primary', $event);
  }
  $eventId = $created->getId();

  // 5) Salva event_id in DB
  $upd = $pdo->prepare("UPDATE transfers_internal SET google_event_id = ? WHERE id = ?");
  $upd->execute([$eventId, $transferId]);

  return $eventId;
}
