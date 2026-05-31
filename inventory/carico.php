<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

ensure_products_active_column($pdo);

$message = '';
$messageType = 'info';
$summary = [];
$warehouses = ['Tizzo','Tramonto'];
$wh = $_POST['warehouse'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } else {
    if (!in_array($wh, $warehouses, true)) $wh = 'Tizzo';

    $valid = [];
    foreach (($_POST['items'] ?? []) as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      if ($pid > 0 && $qty > 0) {
        $valid[] = ['pid' => $pid, 'qty' => $qty];
      }
    }

    if (!$valid) {
      $message = 'Nessun prodotto selezionato: cerca un prodotto, indica la quantità e aggiungilo alla lista.';
      $messageType = 'warning';
    } elseif (empty($user['id'])) {
      $message = 'Sessione non valida: utente non rilevato (created_by).';
      $messageType = 'danger';
    } else {
      $pdo->beginTransaction();
      try {
        $stmtCurrent = $pdo->prepare('SELECT qty FROM stock_levels WHERE product_id = ? AND warehouse = ?');
        $stmtProduct = $pdo->prepare('SELECT title FROM products WHERE id = ? AND COALESCE(is_active, 1) = 1');
        $stmtLvlUp = $pdo->prepare("\n          INSERT INTO stock_levels (product_id, warehouse, qty)\n          VALUES (?, ?, ?)\n          ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)\n        ");
        $stmtMov = $pdo->prepare("\n          INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by)\n          VALUES (?, ?, ?, ?, ?, ?)\n        ");

        foreach ($valid as $v) {
          $stmtProduct->execute([$v['pid']]);
          $title = $stmtProduct->fetchColumn();
          if (!$title) continue;

          $stmtCurrent->execute([$v['pid'], $wh]);
          $prev = (float)($stmtCurrent->fetchColumn() ?: 0);
          $new = $prev + $v['qty'];

          $stmtLvlUp->execute([$v['pid'], $wh, $v['qty']]);
          $stmtMov->execute([$v['pid'], $wh, 'carico', $v['qty'], '', $user['id']]);

          $summary[] = [
            'product_id' => $v['pid'],
            'title' => $title,
            'qty' => $v['qty'],
            'prev' => $prev,
            'now' => $new,
          ];
        }

        $pdo->commit();
        $message = $summary ? 'Carico registrato.' : 'Nessun prodotto caricato.';
        $messageType = $summary ? 'success' : 'warning';
      } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('CARICO-ERR PDO: ' . $e->getMessage() . ' | info=' . json_encode($e->errorInfo));
        $msg = $e->getMessage();
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
          $message = 'Errore: violazione chiave unica (controlla vincoli su stock_levels).';
        } else {
          $message = 'Errore DB durante il carico: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        }
        $messageType = 'danger';
      } catch (Exception $e) {
        $pdo->rollBack();
        error_log('CARICO-ERR GEN: ' . $e->getMessage());
        $message = 'Errore durante il carico: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $messageType = 'danger';
      }
    }
  }
}

$title = 'Carico Magazzino';
$caricoJsVersion = @filemtime(__DIR__ . '/../assets/carico.js') ?: time();
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
  <div>
    <h1 class="h4 mb-1">Carico</h1>
    <div class="text-muted small">Procedura guidata ottimizzata per smartphone: scegli il magazzino, cerca i prodotti e invia il carico.</div>
  </div>
  <div class="d-flex flex-wrap gap-2 no-print">
    <?php if (is_amministrazione()): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/inventory/products.php">Prodotti</a>
      <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/inventory/carico.php">Carico</a>
      <a class="btn btn-sm btn-outline-success" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a>
      <a class="btn btn-sm btn-outline-warning" href="<?= e($base) ?>/inventory/allineamento.php">Allineamento</a>
      <a class="btn btn-sm btn-outline-dark" href="<?= e($base) ?>/inventory/trasferimento.php">Trasferimento</a>
    <?php elseif (is_bar_or_amministrazione()): ?>
      <a class="btn btn-sm btn-outline-success" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="card shadow-sm no-print" id="caricoApp">
  <div class="card-body p-3 p-lg-4">
    <form method="post" id="caricoForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="warehouse" id="caricoWarehouse" value="<?= e(in_array($wh, $warehouses, true) ? $wh : '') ?>">
      <div id="caricoHiddenItems"></div>

      <section class="carico-step" id="warehouseStep">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="badge rounded-pill text-bg-primary">1</span>
          <div>
            <h2 class="h5 mb-0">In quale magazzino carichi?</h2>
            <div class="text-muted small">Seleziona il magazzino di destinazione del carico.</div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <button type="button" class="btn btn-outline-primary warehouse-choice w-100 py-4" data-warehouse="Tizzo">
              <i class="bi bi-building fs-1 d-block mb-2"></i>
              <span class="fs-5 fw-semibold">Tizzo</span>
            </button>
          </div>
          <div class="col-12 col-md-6">
            <button type="button" class="btn btn-outline-success warehouse-choice w-100 py-4" data-warehouse="Tramonto">
              <i class="bi bi-shop-window fs-1 d-block mb-2"></i>
              <span class="fs-5 fw-semibold">Tramonto</span>
            </button>
          </div>
        </div>
      </section>

      <section class="carico-step d-none" id="productStep">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill text-bg-primary">2</span>
            <div>
              <h2 class="h5 mb-0">Aggiungi prodotti</h2>
              <div class="text-muted small">Magazzino selezionato: <strong id="selectedWarehouseLabel"></strong></div>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary align-self-start" id="changeWarehouseBtn"><i class="bi bi-arrow-left me-1"></i>Cambia magazzino</button>
        </div>

        <label class="form-label fw-semibold" for="caricoSearch">Cerca prodotto</label>
        <div class="position-relative mb-2">
          <input id="caricoSearch" type="search" class="form-control form-control-lg" placeholder="Scrivi titolo o EAN del prodotto">
          <div class="list-group position-absolute w-100 z-3 shadow-sm" id="caricoResults" style="max-height:260px; overflow:auto;"></div>
        </div>
        <div class="form-text mb-3">Tocca un prodotto dall’elenco: si aprirà il popup per inserire la quantità da caricare.</div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h3 class="h6 mb-0">Prodotti da caricare</h3>
          <span class="badge text-bg-light" id="itemsCount">0 prodotti</span>
        </div>
        <div id="caricoCartEmpty" class="border rounded-3 p-4 text-center text-muted bg-light">Nessun prodotto aggiunto.</div>
        <div class="list-group mb-3" id="caricoCart"></div>

        <button class="btn btn-primary btn-lg w-100" id="submitCaricoBtn" disabled>
          <i class="bi bi-send-check me-1"></i>Invia Carico
        </button>
      </section>
    </form>
  </div>
</div>

<?php if ($summary): ?>
<div class="card shadow-sm mt-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Riepilogo Carico</h2>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Prodotto</th><th>Q.tà</th><th>Prima</th><th>Dopo</th></tr></thead>
        <tbody>
          <?php foreach($summary as $s): ?>
          <tr>
            <td><?= e($s['title']) ?></td>
            <td><?= e((float)$s['qty']) ?></td>
            <td><?= e((float)$s['prev']) ?></td>
            <td><span class="badge text-bg-success"><?= e((float)$s['now']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="qtyModal" tabindex="-1" aria-labelledby="qtyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qtyModalLabel">Quantità da caricare</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="fw-semibold" id="qtyProductTitle"></div>
        <div class="text-muted small mb-3" id="qtyProductStock"></div>
        <label class="form-label" for="qtyInput">Quantità</label>
        <input type="number" class="form-control form-control-lg" id="qtyInput" step="0.001" min="0.001" inputmode="decimal">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn btn-primary" id="confirmQtyBtn">Aggiungi alla lista</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>/assets/carico.js?v=<?= (int)$caricoJsVersion ?>" defer></script>
<style>
@media print { .no-print { display: none !important; } }
.warehouse-choice { min-height: 150px; border-width: 2px; }
.warehouse-choice.active { box-shadow: 0 0 0 .25rem rgba(13,110,253,.15); }
#caricoResults:empty { display:none; }
@media (max-width: 575.98px) {
  .warehouse-choice { min-height: 130px; }
  #caricoCart .list-group-item { padding: 1rem; }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
