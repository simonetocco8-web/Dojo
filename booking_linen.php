<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/booking_linen.php';
require_login();
$user = current_user();
if (!user_is_reception_or_amministrazione($user)) { http_response_code(403); exit('Accesso negato.'); }
$pdo = db(); ensure_booking_linen_table($pdo);
$env = require __DIR__ . '/config/env.php'; $base = rtrim($env['app']['base_url'] ?? '', '/');
$error = ''; $result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
    try {
        $file = $_FILES['bookings_csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Caricamento non riuscito: seleziona un CSV entro 5 MB.');
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv' || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Seleziona un file CSV entro 5 MB.');
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) throw new RuntimeException('File non leggibile.');
        $result = booking_linen_import($pdo, $content);
        $_SESSION['booking_linen_import_result'] = $result;
        header('Location: ' . $base . '/booking_linen.php'); exit;
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$result = $_SESSION['booking_linen_import_result'] ?? null; unset($_SESSION['booking_linen_import_result']);
$today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$bookings = $pdo->query('SELECT * FROM booking_linen_reservations ORDER BY check_in,reference')->fetchAll(PDO::FETCH_ASSOC);
$changes = booking_linen_schedule($bookings);
$title = 'Import prenotazioni e cambi biancheria'; include __DIR__ . '/partials/header.php';
?>
<h1 class="h4">Prenotazioni e cambi biancheria <span class="badge text-bg-warning">Sperimentale</span></h1>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert alert-success">Import completato: <?= (int)$result['created'] ?> nuove, <?= (int)$result['updated'] ?> aggiornate, <?= (int)$result['unchanged'] ?> invariate, <?= (int)$result['skipped'] ?> righe escluse e <?= (int)$result['expired'] ?> prenotazioni scadute eliminate.</div><?php endif; ?>
<div class="card mb-4"><div class="card-body">
<h2 class="h5">Carica il CSV giornaliero</h2>
<p class="text-muted">Vengono importate solo prenotazioni “Confermate” con più di 7 notti. I riferimenti già presenti vengono aggiornati solo nei campi modificati. Dopo ogni import vengono eliminate le prenotazioni con check-out precedente a oggi. L’assenza di una prenotazione dal CSV non la elimina.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label class="form-label" for="bookingsCsv">Elenco prenotazioni (CSV, massimo 5 MB)</label><input class="form-control mb-3" type="file" id="bookingsCsv" name="bookings_csv" accept=".csv,text/csv" required><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Importa prenotazioni</button></form>
</div></div>
<div class="card"><div class="card-body"><h2 class="h5">Cambi biancheria da fare</h2>
<p class="small text-muted">Un cambio a metà soggiorno, arrotondando per difetto le notti dispari. Per camera: una matrimoniale per i primi 2 adulti, una singola per ogni adulto aggiuntivo e per ogni bambino. È previsto un set da bagno per ogni persona, inclusi bambini ed eventuali neonati. Le prenotazioni con almeno 4 adulti sono sottolineate e da verificare. Eventuali neonati sono segnalati: la loro biancheria va verificata.</p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Data cambio</th><th>Riferimento</th><th>Prenotante</th><th>Camera</th><th>Occupanti</th><th>Matrimoniali</th><th>Singole</th><th>Set da bagno</th><th>Note</th></tr></thead><tbody>
<?php if (!$changes): ?><tr><td colspan="9" class="text-muted text-center">Nessun cambio previsto.</td></tr><?php endif; ?>
<?php foreach ($changes as $change): ?><tr class="<?= $change['review'] ? 'table-warning' : '' ?>"><td class="text-nowrap"><?= e((new DateTimeImmutable($change['date']))->format('d/m/Y')) ?><?php if ($change['date'] === $today): ?> <span class="badge text-bg-primary">Oggi</span><?php elseif ($change['date'] < $today): ?> <span class="badge text-bg-secondary">Data passata</span><?php endif; ?></td><td><?= e($change['reference']) ?></td><td class="<?= $change['adults'] >= 4 ? 'text-decoration-underline' : '' ?>"><?= e($change['booker']) ?></td><td><?= e($change['room']) ?></td><td><?= $change['adults'] ?> adulti, <?= $change['children'] ?> bambini<?php if ($change['infants']): ?>, <?= $change['infants'] ?> neonati<?php endif; ?></td><td><?= $change['double'] ?></td><td><?= $change['single'] ?></td><td><?= $change['bath_sets'] ?></td><td><?= $change['review'] ? 'Verificare composizione biancheria' : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<?php include __DIR__ . '/partials/footer.php'; ?>
