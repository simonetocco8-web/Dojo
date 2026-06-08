<?php
require_once __DIR__ . '/core/db.php';

$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
ensure_transfer_external_travel_columns($pdo);

function h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function public_transfer_type_label(string $type): string {
  return match ($type) {
    'arrivo' => 'Arrivo',
    'partenza' => 'Partenza',
    'arrivo_partenza' => 'Arrivo e Partenza',
    default => ucfirst($type),
  };
}

function public_transfer_datetime(?string $value): string {
  if (!$value) return '—';
  try { return (new DateTime($value))->format('d/m/Y H:i'); } catch (Exception $e) { return '—'; }
}

function public_transfer_time(?string $value): string {
  return $value ? substr($value, 0, 5) : '—';
}

function public_transfer_details(array $row): array {
  $details = [
    'Tipo' => public_transfer_type_label((string)($row['type'] ?? '')),
    'Camera' => (string)($row['room_number'] ?? '—'),
    'Nominativo' => (string)($row['guest_name'] ?? '—'),
    'Numero persone' => isset($row['people_count']) ? (string)(int)$row['people_count'] : '—',
    'Prezzo' => $row['price_eur'] !== null ? '€ ' . number_format((float)$row['price_eur'], 2, ',', '.') : '—',
  ];

  if (($row['type'] ?? '') === 'arrivo_partenza') {
    $details['Luogo arrivo'] = (string)($row['arrival_place'] ?? '—');
    $details['Data/Ora arrivo'] = public_transfer_datetime($row['arrival_date_time'] ?? null);
    $details['Pickup arrivo'] = public_transfer_time($row['arrival_pickup_time'] ?? null);
    if (!empty($row['arrival_flight_number'])) $details['Numero volo arrivo'] = (string)$row['arrival_flight_number'];
    if (!empty($row['arrival_train_number'])) $details['Numero treno arrivo'] = (string)$row['arrival_train_number'];
    $details['Luogo partenza'] = (string)($row['departure_place'] ?? '—');
    $details['Data/Ora partenza'] = public_transfer_datetime($row['departure_date_time'] ?? null);
    $details['Pickup partenza'] = public_transfer_time($row['departure_pickup_time'] ?? null);
    if (!empty($row['departure_flight_number'])) $details['Numero volo partenza'] = (string)$row['departure_flight_number'];
    if (!empty($row['departure_train_number'])) $details['Numero treno partenza'] = (string)$row['departure_train_number'];
  } else {
    $details['Luogo'] = (string)($row['place'] ?? '—');
    $details['Data/Ora'] = public_transfer_datetime($row['date_time'] ?? null);
    $details['Pickup'] = public_transfer_time($row['pickup_time'] ?? null);
    if (!empty($row['flight_number'])) $details['Numero volo'] = (string)$row['flight_number'];
    if (!empty($row['train_number'])) $details['Numero treno'] = (string)$row['train_number'];
  }

  return $details;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$title = 'Risposta transfer';
$message = '';
$error = '';
$row = null;
$needsReason = false;

if (!in_array($action, ['confirm', 'reject'], true) || $token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
  $error = 'Link non valido.';
} else {
  $column = $action === 'confirm' ? 'supplier_confirm_token' : 'supplier_reject_token';
  $stmt = $pdo->prepare("SELECT * FROM transfers_external WHERE {$column} = ? AND deleted_at IS NULL LIMIT 1");
  $stmt->execute([$token]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  if (!$row) {
    $error = 'Link non valido o già non disponibile.';
  } elseif (empty($row['supplier_token_expires_at']) || strtotime((string)$row['supplier_token_expires_at']) < time()) {
    $error = 'Questo link è scaduto. Per sicurezza i link sono validi 24 ore.';
  } elseif (($row['status'] ?? '') === 'rifiutato') {
    $message = 'Questo transfer risulta già rifiutato.';
  } elseif ($action === 'reject' && ($row['status'] ?? '') === 'prenotato') {
    $message = 'Questo transfer risulta già confermato.';
  } elseif ($action === 'confirm') {
    $pdo->prepare("UPDATE transfers_external
                   SET booked = 1,
                       status = 'prenotato',
                       supplier_responded_at = NOW(),
                       rejection_reason = NULL
                   WHERE id = ?")->execute([(int)$row['id']]);
    $row['booked'] = 1;
    $row['status'] = 'prenotato';
    $row['rejection_reason'] = null;
    $message = 'Grazie, la prenotazione del transfer è stata confermata.';
  } else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $reason = trim((string)($_POST['rejection_reason'] ?? ''));
      if ($reason === '') {
        $needsReason = true;
        $error = 'Inserisci la motivazione del rifiuto.';
      } else {
        if (function_exists('mb_substr')) {
          $reason = mb_substr($reason, 0, 255, 'UTF-8');
        } else {
          $reason = substr($reason, 0, 255);
        }
        $pdo->prepare("UPDATE transfers_external
                       SET booked = 0,
                           status = 'rifiutato',
                           rejection_reason = ?,
                           supplier_responded_at = NOW()
                       WHERE id = ?")->execute([$reason, (int)$row['id']]);
        $row['booked'] = 0;
        $row['status'] = 'rifiutato';
        $row['rejection_reason'] = $reason;
        $message = 'Grazie, il rifiuto del transfer è stato registrato.';
      }
    } else {
      $needsReason = true;
      $message = 'Indica la motivazione del rifiuto del transfer.';
    }
  }
}
$details = $row ? public_transfer_details($row) : [];
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <main class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h1 class="h4 mb-3"><?= h($title) ?></h1>
            <?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>
            <?php if ($message): ?><div class="alert alert-info"><?= h($message) ?></div><?php endif; ?>

            <?php if ($needsReason && $row): ?>
              <form method="post" class="mb-4">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <label class="form-label" for="rejection_reason">Motivazione del rifiuto</label>
                <input type="text" class="form-control" id="rejection_reason" name="rejection_reason" maxlength="255" required autofocus>
                <div class="mt-3">
                  <button class="btn btn-danger">Invia rifiuto</button>
                </div>
              </form>
            <?php endif; ?>

            <?php if ($details): ?>
              <h2 class="h6 mt-4">Riepilogo transfer</h2>
              <dl class="row mb-0">
                <?php foreach ($details as $label => $value): ?>
                  <dt class="col-sm-4"><?= h($label) ?></dt>
                  <dd class="col-sm-8"><?= h($value) ?></dd>
                <?php endforeach; ?>
              </dl>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
