<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
?>
<?php
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$message = '';
$warehouses = ['Tizzo','Tramonto'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $from = $_POST['from_wh'] ?? 'Tizzo';
    $to   = $_POST['to_wh'] ?? 'Tramonto';
    if (!in_array($from,$warehouses,true)) $from='Tizzo';
    if (!in_array($to,$warehouses,true)) $to='Tramonto';
    if ($from === $to) {
      $message = 'Scegli magazzini diversi.';
    } else {
      $items = $_POST['items'] ?? [];
      $pdo->beginTransaction();
      try {
        foreach ($items as $it) {
          $pid = (int)($it['product_id'] ?? 0);
          $qty = (float)($it['qty'] ?? 0);
          if ($pid>0 && $qty>0) {
            $st = $pdo->prepare("SELECT qty FROM stock_levels WHERE product_id=? AND warehouse=?");
            $st->execute([$pid,$from]);
            $curFrom = (float)($st->fetchColumn() ?: 0);
            $newFrom = max($curFrom - $qty, 0);
            $pdo->prepare("INSERT INTO stock_levels (product_id, warehouse, qty) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE qty = VALUES(qty)")->execute([$pid,$from,$newFrom]);
            $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by, transfer_to) VALUES (?,?,?,?,?,?,?)")
                ->execute([$pid,$from,'trasferimento',-abs($qty),'', $user['id'], $to]);
            $pdo->prepare("INSERT INTO stock_levels (product_id, warehouse, qty) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)")->execute([$pid,$to,$qty]);
            $pdo->prepare("INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$pid,$to,'trasferimento',abs($qty),'', $user['id']]);
          }
        }
        $pdo->commit();
        $message = 'Trasferimento completato.';
      } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Errore trasferimento.';
      }
    }
  }
}

$title = 'Trasferimento Magazzini';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
<h1 class="h5 mb-3">Trasferimento tra Magazzini</h1>
<?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
<div class="d-flex gap-2">
    <?php if (is_amministrazione()): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/inventory/products.php">Prodotti</a>
      <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/inventory/carico.php">Carico</a>
      <a class="btn btn-sm btn-outline-success" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a>
      <a class="btn btn-sm btn-outline-dark" href="<?= e($base) ?>/inventory/trasferimento.php">Trasferimento</a>
    <?php elseif (is_bar_or_amministrazione()): ?>
      <a class="btn btn-sm btn-outline-success" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a>
    <?php endif; ?>
  </div>
  </div>
<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" id="trasfForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Da</label>
          <select name="from_wh" class="form-select">
            <option value="Tizzo">Tizzo</option>
            <option value="Tramonto">Tramonto</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">A</label>
          <select name="to_wh" class="form-select">
            <option value="Tizzo">Tizzo</option>
            <option value="Tramonto" selected>Tramonto</option>
          </select>
        </div>
      </div>
      <hr>
      <div id="items"></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.invAddRow()">+ Prodotto</button>
      <div class="mt-3">
        <button class="btn btn-dark">Esegui Trasferimento</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= e($base) ?>/assets/trasferimento.js" defer></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
