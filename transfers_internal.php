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

$title = 'Transfere — Interni';
include __DIR__ . '/partials/header.php';

$rows = $pdo->query('SELECT t.*, u.email AS created_by_email
                     FROM transfers_internal t
                     JOIN users u ON u.id = t.created_by
                     WHERE t.deleted_at IS NULL
                     ORDER BY t.when_at ASC, t.id DESC')->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Transfer Interni</h1>
  <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-info mb-0 ms-3 py-1 px-2"><?= e($_GET['msg']) ?></div>
  <?php endif; ?>
  <div class="d-flex gap-2 ms-auto">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/transfers_internal_blocks.php">Periodi Bloccati</a>
    <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/transfer_internal_create.php">+ Nuovo transfer</a>
  </div>
</div>

<!-- Layout due colonne -->
<div class="container-fluid px-0">
  <div class="row g-3">
    <!-- Colonna SINISTRA: Tabella -->
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header">
          <h2 class="h6 mb-0">Elenco</h2>
        </div>
        <div class="card-body p-0" style="height: calc(100vh - 200px);">
          <div class="overflow-auto h-100">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                  <tr>
                    <th>Data</th>
                    <th>Ora</th>
                    <th>Camera</th>
                    <th>Verso</th>
                    <th>Località</th>
                    <th>Creato da</th>
                    <th>Azioni</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($rows as $r): ?>
                  <tr>
                    <td><?php $dt=new DateTime($r['when_at']); echo $dt->format('d/m/Y'); ?></td>
                    <td><?php $dt=new DateTime($r['when_at']); echo $dt->format('H:i'); ?></td>
                    <td><?= e($r['room_number']) ?></td>
                    <td><?= e(strtoupper($r['direction'])) ?></td>
                    <td><?= e($r['location']) ?></td>
                    <td class="small text-muted"><?= e($r['created_by_email']) ?></td>
                    <td>
                      <a class="btn btn-link p-0 me-2" href="<?= e($base) ?>/transfer_internal_edit.php?id=<?= (int)$r['id'] ?>" title="Modifica" aria-label="Modifica">
                        <i class="bi bi-pencil-square text-primary"></i>
                      </a>
                      <form method="post" action="transfer_internal_delete.php" class="d-inline"
                            data-confirm-message="Confermi l’eliminazione di questo transfer?">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn btn-link p-0" title="Elimina" aria-label="Elimina">
                          <!-- icona trash -->
                          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor"
                               viewBox="0 0 16 16" class="text-danger">
                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5Zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5Zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6Z"/>
                            <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5V1a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1h2.5a1 1 0 0 1 1 1ZM6 1v1h4V1H6Zm6 3H4v9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4Z"/>
                          </svg>
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div><!-- /.table-responsive -->
          </div><!-- /.overflow-auto -->
        </div><!-- /.card-body -->
      </div><!-- /.card -->
    </div><!-- /.col -->

    <!-- Colonna DESTRA: iFrame Google Calendar -->
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h2 class="h6 mb-0">Calendario</h2>
        </div>
        <div class="card-body p-0" style="height: calc(100vh - 200px);">
          <iframe
            src="https://calendar.google.com/calendar/embed?height=600&wkst=2&ctz=Europe%2FRome&showPrint=0&showTz=0&mode=WEEK&title=Transfer&src=ZTUyYjE5MGFiYjEwMWUwMzY4ZTc4NDQ3ZjBhODg1NzQ3NWYwZjMxMTYzZjA3ZTYzNWEzNTczNTRmZGUzODE3ZEBncm91cC5jYWxlbmRhci5nb29nbGUuY29t&color=%23d50000"
            class="w-100 h-100 border-0"
            style="min-height: 400px;"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
          ></iframe>
        </div>
      </div>
    </div><!-- /.col -->
  </div><!-- /.row -->
</div><!-- /.container-fluid -->

<?php include __DIR__ . '/partials/footer.php'; ?>
