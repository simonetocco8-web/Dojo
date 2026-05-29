<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
$msg = $_GET['msg'] ?? '';

if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}

// filtro semplice per mese corrente (opzionale)
$month = $_GET['month'] ?? (new DateTime('first day of this month'))->format('Y-m');
$start = $month.'-01';
$end   = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

$st = $pdo->prepare("
  SELECT d.id, d.day, d.note, d.google_event_id,
         u.nome, u.cognome, u.dipartimento
  FROM days_off d
  JOIN users u ON u.id = d.user_id
  WHERE d.deleted_at IS NULL
    AND d.day BETWEEN ? AND ?
  ORDER BY d.day ASC, u.cognome ASC, u.nome ASC
");
$st->execute([$start, $end]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$title = 'Giorni Liberi';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Giorni Liberi</h1>
  <a class="btn btn-primary btn-sm" href="days_off_create.php">Nuovo</a>
</div>

<?php if ($msg === 'created'): ?>
  <div class="alert alert-success">Giorno libero creato.</div>
<?php elseif ($msg === 'created_repeated'): ?>
  <div class="alert alert-success">Giorni liberi ricorrenti creati: <?= (int)($_GET['count'] ?? 0) ?>.</div>
<?php elseif ($msg === 'updated'): ?>
  <div class="alert alert-success">Prossimo giorno libero sostituito.</div>
<?php elseif ($msg === 'updated_all'): ?>
  <div class="alert alert-success">Giorni liberi futuri aggiornati con la nuova ricorrenza settimanale.</div>
<?php elseif ($msg === 'deleted'): ?>
  <div class="alert alert-success">Giorno libero eliminato.</div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <label class="form-label">Mese</label>
    <input type="month" class="form-control" name="month" value="<?= e($month) ?>">
  </div>
  <div class="col-auto align-self-end">
    <button class="btn btn-outline-secondary btn-sm">Filtra</button>
  </div>
</form>

<div class="row">
  <!-- Colonna sinistra: tabella -->
  <div class="col-md-7">
    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Data</th>
              <th>Utente</th>
              <th>Dipartimento</th>
              <th>Note</th>
              <th class="text-end">Azioni</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (new DateTime($r['day']))->format('d/m/Y') ?></td>
              <td><?= e(trim(($r['cognome'] ?? '').' '.($r['nome'] ?? ''))) ?></td>
              <td><?= e(departments_label($r['dipartimento'])) ?></td>
              <td><?= e($r['note']) ?></td>
              <td class="text-end">
                <form method="post" action="days_off_delete.php" class="d-inline" onsubmit="return confirm('Eliminare questo giorno libero?');">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-link p-0 text-danger" title="Elimina">🗑️</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">Nessun dato</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Colonna destra: iframe -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-body p-0">
        <!-- Qui puoi inserire il tuo iframe, ad esempio Google Calendar -->
        <iframe src="https://calendar.google.com/calendar/embed?src=f110e9509588ceae765a4cf687e66b0fc2865c0e9e33ed337ddbd2a933a8b358%40group.calendar.google.com&ctz=Europe%2FRome" style="border: 0" width="800" height="600" frameborder="0" scrolling="no"></iframe>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
