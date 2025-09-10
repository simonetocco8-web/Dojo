<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$rows = $pdo->query('SELECT t.*, u.email AS created_by_email
                     FROM transfers_external t
                     JOIN users u ON u.id = t.created_by
                     WHERE t.deleted_at IS NULL
                     ORDER BY t.date_time ASC, t.id DESC')->fetchAll();

$title = 'Transfere — Esterni';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Transfer Esterni</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/transfer_external_create.php">+ Nuovo transfer</a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-end mb-2">
      <div class="small text-muted">
        <i class="bi bi-bookmark-check text-primary"></i> Prenotato (con Compagnia) &nbsp;|&nbsp;
        <i class="bi bi-cash-coin text-success"></i> Pagato &nbsp;|&nbsp;
        <i class="bi bi-clock text-info"></i> Imposta Pickup &nbsp;|&nbsp;
        <i class="bi bi-x-octagon text-warning"></i> Annulla/Ripristina &nbsp;|&nbsp;
        <i class="bi bi-trash text-danger"></i> Elimina
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Luogo</th>
            <th>Data/Ora</th>
            <th>Pickup</th>
            <th>Camera</th>
            <th>Nominativo</th>
            <th>Compagnia</th>
            <th class="text-center">Pren.</th>
            <th class="text-center">Pag.</th>
            <th>Stato</th>
            <th>Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?= e(ucfirst($r['type'])) ?></td>
            <td><?= e($r['place'] ?? '') ?></td>
            <td><?php $dt=new DateTime($r['date_time']); echo $dt->format('d/m/y H:i'); ?></td>
            <td><?= $r['pickup_time'] ? e(substr($r['pickup_time'],0,5)) : '—' ?></td>
            <td><?= e($r['room_number']) ?></td>
            <td><?= e($r['guest_name']) ?></td>
            <td><?= $r['booked'] ? e($r['service_company'] ?? '') : '—' ?></td>
            <td class="text-center"><?= $r['booked'] ? '✔' : '—' ?></td>
            <td class="text-center"><?= $r['paid'] ? '✔' : '—' ?></td>
            <td>
              <?php if(($r['status'] ?? 'attivo') === 'annullato'): ?>
                <span class="badge bg-warning text-dark">Annullato</span>
              <?php else: ?>
                <span class="badge bg-success">Attivo</span>
              <?php endif; ?>
            </td>
            <td class="text-nowrap">
              <?php if (!$r['booked']): ?>
                <button class="btn btn-sm btn-outline-primary" title="Imposta Prenotato e Compagnia" data-bs-toggle="collapse" data-bs-target="#book_<?= (int)$r['id'] ?>">
                  <i class="bi bi-bookmark-check"></i>
                </button>
                <div id="book_<?= (int)$r['id'] ?>" class="collapse mt-2">
                  <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="set_booked">
                    <input type="text" name="service_company" class="form-control form-control-sm" placeholder="Compagnia del Servizio" required>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i></button>
                  </form>
                </div>
              <?php else: ?>
                <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline" onsubmit="return confirm('Rimuovere Prenotato e Compagnia?');">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="unset_booked">
                  <button class="btn btn-sm btn-outline-secondary" title="Rimuovi Prenotato">
                    <i class="bi bi-bookmark-dash"></i>
                  </button>
                </form>
              <?php endif; ?>

              <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline ms-1">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="toggle_paid">
                <button class="btn btn-sm btn-outline-success" title="Imposta/Annulla Pagato">
                  <i class="bi bi-cash-coin"></i>
                </button>
              </form>

              <button class="btn btn-sm btn-outline-info ms-1" title="Imposta/Modifica Pickup" data-bs-toggle="collapse" data-bs-target="#pu_<?= (int)$r['id'] ?>">
                <i class="bi bi-clock"></i>
              </button>
              <div id="pu_<?= (int)$r['id'] ?>" class="collapse mt-2">
                <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-flex gap-2">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="set_pickup">
                  <input type="time" name="pickup_time" class="form-control form-control-sm" value="<?= e($r['pickup_time'] ? substr($r['pickup_time'],0,5) : '') ?>">
                  <button class="btn btn-sm btn-info"><i class="bi bi-check2"></i></button>
                </form>
                <div class="form-text">Lascia vuoto e salva per cancellare il Pickup.</div>
              </div>

              <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline ms-1">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="toggle_cancel">
                <button class="btn btn-sm btn-outline-warning" title="Annulla/Ripristina">
                  <i class="bi bi-x-octagon"></i>
                </button>
              </form>

              <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline ms-1" onsubmit="return confirm('Eliminare questo transfer?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-outline-danger" title="Elimina">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
