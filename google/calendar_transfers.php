<?php
require_once  '/home/bwlxtuul/dojo.villaggiotramonto.it/google/google_client.php';

function gcal_create_event_for_internal_transfer(PDO $pdo, int $transferId): ?string {
  $env = require  '/home/bwlxtuul/dojo.villaggiotramonto.it/config/env.php';
  $calendarId = $env['google']['calendar_id'] ?? 'primary';

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
  
   
   
// Crei un oggetto DateTime dalla stringa
  $startDt = new DateTime($t['when_at']);

  
  if (!$startDt) throw new RuntimeException('Data/Ora non valide');
  $endDt = clone $startDt; $endDt->modify('+30 minutes');

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

  $created = $service->events->insert($calendarId, $event);
  $eventId = $created->getId();

  // 5) Salva event_id in DB
  $upd = $pdo->prepare("UPDATE transfers_internal SET google_event_id = ? WHERE id = ?");
  $upd->execute([$eventId, $transferId]);

  return $eventId;
}
