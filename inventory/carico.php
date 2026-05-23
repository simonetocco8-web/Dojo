<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
?>
<?php
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$message = '';
$warehouses = ['Tizzo','Tramonto'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $wh = $_POST['warehouse'] ?? 'Tizzo';
    $warehouses = ['Tizzo','Tramonto'];
    if (!in_array($wh, $warehouses, true)) $wh = 'Tizzo';

    $items = $_POST['items'] ?? [];
    // Filtro righe valide
    $valid = [];
    foreach ($items as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      if ($pid > 0 && $qty > 0) {
        $valid[] = ['pid' => $pid, 'qty' => $qty];
      }
    }

    if (empty($valid)) {
      $message = 'Nessuna riga valida: seleziona un prodotto dai suggerimenti e una quantità > 0.';
    } elseif (empty($user['id'])) {
      $message = 'Sessione non valida: utente non rilevato (created_by).';
    } else {
      $pdo->beginTransaction();
      try {
        // Preparo gli statement una sola volta
        $stmtLvlUp = $pdo->prepare("
          INSERT INTO stock_levels (product_id, warehouse, qty)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        $stmtMov = $pdo->prepare("
          INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, ref, created_by)
          VALUES (?,?,?,?,?,?)
        ");

        foreach ($valid as $v) {
          $stmtLvlUp->execute([$v['pid'], $wh, $v['qty']]);
          $stmtMov->execute([$v['pid'], $wh, 'carico', $v['qty'], '', $user['id']]);
        }

        $pdo->commit();
        $message = 'Carico registrato.';
      } catch (PDOException $e) {
        $pdo->rollBack();
        // LOG dettagliato per capire la causa reale
        error_log('CARICO-ERR PDO: ' . $e->getMessage() . ' | info=' . json_encode($e->errorInfo));
        // Messaggio chiaro per debug (puoi renderlo generico in produzione)
        $msg = $e->getMessage();
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
          $message = 'Errore: violazione chiave unica (controlla vincoli su stock_levels).';
        } else {
          $message = 'Errore DB durante il carico: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        }
      } catch (Exception $e) {
        $pdo->rollBack();
        error_log('CARICO-ERR GEN: ' . $e->getMessage());
        $message = 'Errore durante il carico: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      }
    }
  }
}

$title = 'Carico Magazzino';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Carico</h1>
  <div class="d-flex gap-2">
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
<?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" id="caricoForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Magazzino</label>
          <select name="warehouse" class="form-select">
            <option value="Tizzo">Tizzo</option>
            <option value="Tramonto">Tramonto</option>
          </select>
        </div>
      </div>
      <hr>
      <div id="items"></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.invAddRow()">+ Prodotto</button>
      <div class="mt-3">
        <button class="btn btn-primary">Registra Carico</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= e($base) ?>/assets/carico.js" defer></script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
