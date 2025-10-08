<?php
require_once __DIR__ . '/google_client.php';

function gcal_days_off_create(PDO $pdo, int $daysOffId): ?string {
  $env = require __DIR__ . '/../config/env.php';
  $calendarId = $env['google']['calendar_days_off_id'] ?? 'primary';

  $st = $pdo->prepare("
    SELECT d.id, d.day, d.note, u.nome, u.cognome
    FROM days_off d
    JOIN users u ON u.id = d.user_id
    WHERE d.id = ? AND d.deleted_at IS NULL
    LIMIT 1
  ");
  $st->execute([$daysOffId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) throw new RuntimeException('Record giorno libero non trovato');

  $nome = trim(($r['nome'] ?? '').' '.($r['cognome'] ?? ''));
  $summary = $nome;
  $desc = 'Giorno libero per '.$nome.(empty($r['note']) ? '' : "\nNote: ".$r['note']);

  // Evento tutto il giorno: end = giorno successivo
  $startDate = (new DateTimeImmutable($r['day']))->format('Y-m-d');
  $endDate   = (new DateTimeImmutable($r['day']))->modify('+1 day')->format('Y-m-d');

  $service = google_calendar_client();
  $event = new Google\Service\Calendar\Event([
    'summary'     => $summary,
    'description' => $desc,
    'start' => [ 'date' => $startDate, 'timeZone' => 'Europe/Rome' ],
    'end'   => [ 'date' => $endDate,   'timeZone' => 'Europe/Rome' ],
    // opzionale: colore dedicato (es. giallo)
    // 'colorId' => '5',
  ]);

  $created = $service->events->insert($calendarId, $event);
  $eventId = $created->getId();

  $upd = $pdo->prepare("UPDATE days_off SET google_event_id = ? WHERE id = ?");
  $upd->execute([$eventId, $daysOffId]);

  return $eventId;
}

function gcal_days_off_delete(PDO $pdo, int $daysOffId): void {
  $env = require __DIR__ . '/../config/env.php';
  $calendarId = $env['google']['calendar_days_off_id'] ?? 'primary';

  $st = $pdo->prepare("SELECT google_event_id FROM days_off WHERE id = ? LIMIT 1");
  $st->execute([$daysOffId]);
  $eventId = (string)$st->fetchColumn();
  if (!$eventId) return;

  try {
    $service = google_calendar_client();
    $service->events->delete($calendarId, $eventId);
  } catch (Throwable $e) {
    error_log('GCAL days_off delete failed: '.$e->getMessage());
  }
}
