<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_transfer_external_travel_columns($pdo);
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$rows = $pdo->query('SELECT t.*, u.email AS created_by_email
                     FROM transfers_external t
                     JOIN users u ON u.id = t.created_by
                     WHERE t.deleted_at IS NULL
                     ORDER BY t.date_time ASC, t.id DESC')->fetchAll();

$unpaidTotalsBySupplier = [];
foreach ($rows as $r) {
    if (!($r['paid'] ?? 0)) {
        $supplier = trim((string)($r['supplier_name'] ?? ''));
        if ($supplier === '') {
            $supplier = '—';
        }

        $price = $r['price_eur'];
        if ($price !== null && $price !== '') {
            $unpaidTotalsBySupplier[$supplier] = ($unpaidTotalsBySupplier[$supplier] ?? 0) + (float)$price;
        }
    }
}

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
        <i class="bi bi-bookmark-check text-primary"></i> Prenotato &nbsp;|&nbsp;
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
            <th>Rif.</th>
            <th>Camera</th>
            <th>Nominativo</th>
            <th>Fornitore</th>
            <th class="text-center">Persone</th>
            <th>Prezzo</th>
            <th class="text-center">Pren.</th>
            <th class="text-center">Pag.</th>
            <th>Stato</th>
            <th>Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <?php
            $isRoundTrip = ($r['type'] ?? '') === 'arrivo_partenza';
            $typeLabel = match ($r['type'] ?? '') {
              'arrivo' => 'Arrivo',
              'partenza' => 'Partenza',
              'arrivo_partenza' => 'Arrivo e Partenza',
              default => ucfirst((string)($r['type'] ?? '')),
            };
            $typeIcons = match ($r['type'] ?? '') {
              'arrivo' => [['label' => 'A', 'alt' => 'Arrivo']],
              'partenza' => [['label' => 'P', 'alt' => 'Partenza']],
              'arrivo_partenza' => [
                ['label' => 'A', 'alt' => 'Arrivo'],
                ['label' => 'P', 'alt' => 'Partenza'],
              ],
              default => [],
            };
            $dateLabel = '—';
            if (!empty($r['date_time'])) {
              $dateLabel = (new DateTime($r['date_time']))->format('d/m/y H:i');
            }
            $referenceParts = [];
            if ($isRoundTrip) {
              if (!empty($r['arrival_flight_number'])) $referenceParts[] = 'Volo arrivo: ' . $r['arrival_flight_number'];
              if (!empty($r['arrival_train_number'])) $referenceParts[] = 'Treno arrivo: ' . $r['arrival_train_number'];
              if (!empty($r['departure_flight_number'])) $referenceParts[] = 'Volo partenza: ' . $r['departure_flight_number'];
              if (!empty($r['departure_train_number'])) $referenceParts[] = 'Treno partenza: ' . $r['departure_train_number'];
            } else {
              if (!empty($r['flight_number'])) $referenceParts[] = 'Volo: ' . $r['flight_number'];
              if (!empty($r['train_number'])) $referenceParts[] = 'Treno: ' . $r['train_number'];
            }
          ?>
          <tr>
            <td class="text-center">
              <?php if ($typeIcons): ?>
                <span class="d-inline-flex align-items-center justify-content-center gap-1" title="<?= e($typeLabel) ?>" aria-label="<?= e($typeLabel) ?>">
                  <?php foreach ($typeIcons as $icon): ?>
                    <span class="d-inline-flex align-items-center justify-content-center rounded border bg-white text-dark fw-bold" title="<?= e($icon['alt']) ?>" aria-label="<?= e($icon['alt']) ?>" style="width:24px;height:24px;font-size:0.85rem;line-height:1;"><?= e($icon['label']) ?></span>
                  <?php endforeach; ?>
                </span>
              <?php else: ?>
                <?= e($typeLabel) ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isRoundTrip): ?>
                <div><strong>Arrivo:</strong> <?= e($r['arrival_place'] ?? '') ?></div>
                <div><strong>Partenza:</strong> <?= e($r['departure_place'] ?? '') ?></div>
              <?php else: ?>
                <?= e($r['place'] ?? '') ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isRoundTrip): ?>
                <div><strong>Arrivo:</strong> <?= !empty($r['arrival_date_time']) ? e((new DateTime($r['arrival_date_time']))->format('d/m/y H:i')) : '—' ?></div>
                <div><strong>Partenza:</strong> <?= !empty($r['departure_date_time']) ? e((new DateTime($r['departure_date_time']))->format('d/m/y H:i')) : '—' ?></div>
              <?php else: ?>
                <?= e($dateLabel) ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isRoundTrip): ?>
                <div><strong>Arrivo:</strong> <?= !empty($r['arrival_pickup_time']) ? e(substr($r['arrival_pickup_time'], 0, 5)) : '—' ?></div>
                <div><strong>Partenza:</strong> <?= !empty($r['departure_pickup_time']) ? e(substr($r['departure_pickup_time'], 0, 5)) : '—' ?></div>
              <?php else: ?>
                <?= $r['pickup_time'] ? e(substr($r['pickup_time'],0,5)) : '—' ?>
              <?php endif; ?>
            </td>
            <td><?= $referenceParts ? e(implode(' · ', $referenceParts)) : '—' ?></td>
            <td><?= e($r['room_number']) ?></td>
            <td><?= e($r['guest_name']) ?></td>
            <td><?= trim((string)($r['supplier_name'] ?? '')) !== '' ? e($r['supplier_name']) : '—' ?></td>
            <td class="text-center"><?= $r['people_count'] !== null ? e((int)$r['people_count']) : '—' ?></td>
            <td>
              <?php if ($r['price_eur'] !== null): ?>
                € <?= e(number_format((float)$r['price_eur'], 2, ',', '.')) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="text-center"><?= $r['booked'] ? '✔' : '—' ?></td>
            <td class="text-center"><?= $r['paid'] ? '✔' : '—' ?></td>
            <td>
              <?php if(($r['status'] ?? 'attivo') === 'rifiutato'): ?>
                <span class="badge bg-danger">Rifiutato</span>
                <button type="button" class="btn btn-sm btn-outline-danger ms-1" title="Motivazione rifiuto" data-bs-toggle="modal" data-bs-target="#rejectReason_<?= (int)$r['id'] ?>">
                  <i class="bi bi-exclamation-octagon"></i>
                </button>
                <div class="modal fade" id="rejectReason_<?= (int)$r['id'] ?>" tabindex="-1" aria-labelledby="rejectReasonLabel_<?= (int)$r['id'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="rejectReasonLabel_<?= (int)$r['id'] ?>">Motivazione rifiuto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                      </div>
                      <div class="modal-body"><?= e($r['rejection_reason'] ?: 'Nessuna motivazione indicata.') ?></div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php elseif(($r['status'] ?? 'attivo') === 'prenotato'): ?>
                <span class="badge bg-primary">Prenotato</span>
              <?php elseif(($r['status'] ?? 'attivo') === 'annullato'): ?>
                <span class="badge bg-warning text-dark">Annullato</span>
              <?php else: ?>
                <span class="badge bg-success">Attivo</span>
              <?php endif; ?>
            </td>
            <td class="text-nowrap">
              <?php if (!$r['booked']): ?>
                <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="set_booked">
                  <button class="btn btn-sm btn-outline-primary" title="Imposta Prenotato">
                    <i class="bi bi-bookmark-check"></i>
                  </button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline" data-confirm-message="Rimuovere Prenotato?">
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

              <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline ms-1" data-confirm-message="Confermi di voler annullare o ripristinare questo transfer?">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="toggle_cancel">
                <button class="btn btn-sm btn-outline-warning" title="Annulla/Ripristina">
                  <i class="bi bi-x-octagon"></i>
                </button>
              </form>

              <form method="post" action="<?= e($base) ?>/transfer_external_action.php" class="d-inline ms-1" data-confirm-message="Eliminare questo transfer?">
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
    <?php if (!empty($unpaidTotalsBySupplier)): ?>
      <div class="mt-4 p-3 border rounded bg-light">
        <h2 class="h6 mb-3">Totali non pagati per Fornitore</h2>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Fornitore</th>
                <th class="text-end">Totale non pagato</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($unpaidTotalsBySupplier as $supplier => $total): ?>
              <tr>
                <td><?= e($supplier) ?></td>
                <td class="text-end">€ <?= e(number_format((float)$total, 2, ',', '.')) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
