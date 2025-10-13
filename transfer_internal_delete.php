<?php
// inventory/transfer_internal_delete.php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';

// (opzionale ma consigliato) helper Google già visto in precedenza
require_once __DIR__ . '/google/google_client.php';         // google_calendar_client()
$env = require __DIR__ . '/config/env.php';


$pdo  = db();
$user = current_user();

// Permessi: limita a admin (adatta se vuoi estendere ad altri ruoli)
if (!$user || !user_is_reception_or_amministrazione($user)) {
  http_response_code(403);
  exit('Permesso negato.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Metodo non consentito.');
}

if (!csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(400);
  exit('Token CSRF non valido.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('ID non valido.');
}

$pdo = db();

// 1) Recupera il transfer per leggere l'event_id
$st = $pdo->prepare("SELECT id, google_event_id FROM transfers_internal WHERE id = ? LIMIT 1");
$st->execute([$id]);
$transfer = $st->fetch(PDO::FETCH_ASSOC);
if (!$transfer) {
  http_response_code(404);
  exit('Transfer non trovato.');
}

// 2) Se c'è un event_id, prova a rimuoverlo da Google Calendar (best-effort)
if (!empty($transfer['google_event_id'])) {
  try {
    $calendarId = $env['google']['calendar_id'] ?? 'primary';
    $service = google_calendar_client(); // richiede token oauth già configurato
    $service->events->delete($calendarId, $transfer['google_event_id']);
  } catch (Throwable $e) {
    // Non bloccare l’eliminazione locale: log e procedi
    error_log('GCAL delete failed (transfer '.$id.'): '.$e->getMessage());
  }
}

// 3) Elimina il transfer dal DB
$del = $pdo->prepare("DELETE FROM transfers_internal WHERE id = ?");
$del->execute([$id]);

// Redirect alla lista con messaggio
header('Location: transfers_internal.php?msg=deleted');
exit;
