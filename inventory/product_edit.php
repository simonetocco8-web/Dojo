<?php
// inventory/product_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
$env  = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_products_url_column($pdo);
ensure_suppliers_active_column($pdo);
ensure_product_categories_table($pdo);
$user = current_user();

// Solo Amministrazione o Admin
if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}

// --- Config “statiche” del form (adatta se servono) ---
$CATEGORIES = $pdo->query("SELECT name FROM product_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (!$CATEGORIES) {
  $CATEGORIES = ['Bibite','Caffetteria','Colazione','Pulizia','Rosticceria'];
}
$UNITS      = ['pacco','cartone','blister','Bottiglia','Busta','confezione'];

// --- Carica ID prodotto ---
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID non valido.'); }

// --- Carica prodotto ---
$st = $pdo->prepare("SELECT id, title, ean13, min_qty, max_qty, category, unit, supplier_id, product_url FROM products WHERE id = ?");
$st->execute([$id]);
$product = $st->fetch(PDO::FETCH_ASSOC);
if (!$product) { http_response_code(404); exit('Prodotto non trovato.'); }

// --- Carica fornitori per la tendina ---
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE COALESCE(is_active, 1) = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$supplierNameById = array_column($suppliers, 'name', 'id');

// --- Helpers ---
$errors = [];
$flash  = '';

function supplier_options(array $suppliers, ?int $selectedId = null): string {
  $html = '<option value="">— Nessuno —</option>';
  foreach ($suppliers as $s) {
    $sel = ($selectedId !== null && (int)$s['id'] === (int)$selectedId) ? ' selected' : '';
    $isInternet = strcasecmp(trim((string)$s['name']), 'Internet') === 0 ? '1' : '0';
    $html .= '<option value="'.(int)$s['id'].'" data-supplier-name="'.htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8').'" data-is-internet="'.$isInternet.'"'.$sel.'>'.htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8').'</option>';
  }
  return $html;
}

// --- Handle POST (update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Token CSRF non valido.';
  } else {
    // 1) Leggi input
    $title       = trim((string)($_POST['title'] ?? ''));
    $ean13       = preg_replace('/\D+/', '', (string)($_POST['ean13'] ?? '')); // solo cifre
    $category    = trim((string)($_POST['category'] ?? ''));
    $unit        = trim((string)($_POST['unit'] ?? ''));
    $min_qty     = (string)($_POST['min_qty'] ?? '');
    $max_qty     = (string)($_POST['max_qty'] ?? '');
    $supplier_id = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' ? (int)$_POST['supplier_id'] : null;
    $product_url = trim((string)($_POST['product_url'] ?? ''));
    $product_url = $product_url !== '' ? $product_url : null;
    $isInternetSupplier = $supplier_id !== null && strcasecmp((string)($supplierNameById[$supplier_id] ?? ''), 'Internet') === 0;

    // 2) Validazioni base
    if ($title === '') {
      $errors[] = 'Inserisci il titolo.';
    }
    if ($ean13 !== '' && !preg_match('/^\d{13}$/', $ean13)) {
      $errors[] = 'EAN13 deve contenere esattamente 13 cifre.';
    }
    if ($category !== '' && !in_array($category, $CATEGORIES, true)) {
      $errors[] = 'Categoria non valida.';
    }
    if ($unit !== '' && !in_array($unit, $UNITS, true)) {
      $errors[] = 'Unità di misura non valida.';
    }
    $min_qty = ($min_qty === '') ? null : (float)$min_qty;
    $max_qty = ($max_qty === '') ? null : (float)$max_qty;
    if ($min_qty !== null && $min_qty < 0) $errors[] = 'La quantità minima non può essere negativa.';
    if ($max_qty !== null && $max_qty < 0) $errors[] = 'La quantità massima non può essere negativa.';
    if ($min_qty !== null && $max_qty !== null && $min_qty > $max_qty) {
      $errors[] = 'La quantità minima non può superare la quantità massima.';
    }

    // 3) Fornitore esistente?
    if ($supplier_id !== null) {
      $chk = $pdo->prepare("SELECT 1 FROM suppliers WHERE id=? AND COALESCE(is_active, 1) = 1");
      $chk->execute([$supplier_id]);
      if (!$chk->fetchColumn()) {
        $errors[] = 'Fornitore selezionato non valido o non attivo.';
        $supplier_id = null;
      }
    }
    if ($product_url !== null && !filter_var($product_url, FILTER_VALIDATE_URL)) {
      $errors[] = 'Inserisci un URL valido.';
    }
    if (!$isInternetSupplier) {
      $product_url = null;
    }

    // 4) Unicità EAN13 (se valorizzato)
    if ($ean13 !== '') {
      $u = $pdo->prepare("SELECT id FROM products WHERE ean13 = ? AND id <> ? LIMIT 1");
      $u->execute([$ean13, $id]);
      if ($u->fetchColumn()) {
        $errors[] = 'EAN13 già presente su un altro prodotto.';
      }
    }

    // 5) Se ok, aggiorna
    if (!$errors) {
      try {
        $upd = $pdo->prepare("
          UPDATE products
          SET title = ?, ean13 = ?, category = ?, unit = ?, min_qty = ?, max_qty = ?, supplier_id = ?, product_url = ?
          WHERE id = ?
        ");
        $upd->execute([
          $title,
          ($ean13 !== '' ? $ean13 : null),
          ($category !== '' ? $category : null),
          ($unit !== '' ? $unit : null),
          $min_qty,
          $max_qty,
          $supplier_id,
          $product_url,
          $id
        ]);

        // Ricarica i dati aggiornati
        $st->execute([$id]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        $flash = 'Prodotto aggiornato con successo.';
      } catch (Throwable $e) {
        $errors[] = 'Errore durante l’aggiornamento: '.$e->getMessage();
      }
    }
  }
}

$titlePage = 'Modifica Prodotto';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= e($titlePage) ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/inventory/products.php">Torna alla lista</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success"><?= e($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-warning mb-3">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Titolo *</label>
          <input type="text" name="title" class="form-control" required
                 value="<?= e($product['title'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">EAN13</label>
          <input type="text" name="ean13" maxlength="13" pattern="\d{13}" class="form-control"
                 value="<?= e($product['ean13'] ?? '') ?>" placeholder="13 cifre">
        </div>

        <div class="col-md-3">
          <label class="form-label">Fornitore</label>
          <select name="supplier_id" class="form-select" id="supplier_id" onchange="toggleProductUrlField()">
            <?= supplier_options($suppliers, $product['supplier_id'] !== null ? (int)$product['supplier_id'] : null) ?>
          </select>
        </div>

        <div class="col-md-12 d-none" id="productUrlField">
          <label class="form-label">Url</label>
          <input type="url" name="product_url" class="form-control" value="<?= e($product['product_url'] ?? '') ?>" placeholder="https://...">
        </div>

        <div class="col-md-4">
          <label class="form-label">Categoria</label>
          <select name="category" class="form-select">
            <option value="">— Nessuna —</option>
            <?php foreach ($CATEGORIES as $c): ?>
              <option value="<?= e($c) ?>" <?= ($product['category'] === $c ? 'selected' : '') ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Unità di misura</label>
          <select name="unit" class="form-select">
            <option value="">— Nessuna —</option>
            <?php foreach ($UNITS as $u): ?>
              <option value="<?= e($u) ?>" <?= ($product['unit'] === $u ? 'selected' : '') ?>><?= e($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Q.tà minima</label>
          <input type="number" step="0.01" name="min_qty" class="form-control"
                 value="<?= $product['min_qty'] !== null ? (float)$product['min_qty'] : '' ?>">
        </div>

        <div class="col-md-2">
          <label class="form-label">Q.tà massima</label>
          <input type="number" step="0.01" name="max_qty" class="form-control"
                 value="<?= $product['max_qty'] !== null ? (float)$product['max_qty'] : '' ?>">
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">Salva modifiche</button>
        <a class="btn btn-outline-secondary" href="<?= e($base) ?>/inventory/products_list.php">Annulla</a>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  function isInternetSupplier(option) {
    if (!option) return false;
    var supplierName = option.getAttribute('data-supplier-name') || option.text || '';
    return option.getAttribute('data-is-internet') === '1' || supplierName.trim().toLowerCase() === 'internet';
  }

  window.toggleProductUrlField = function () {
    var supplierSelect = document.getElementById('supplier_id');
    var urlField = document.getElementById('productUrlField');
    if (!supplierSelect || !urlField) return;
    var option = supplierSelect.options[supplierSelect.selectedIndex];
    urlField.classList.toggle('d-none', !isInternetSupplier(option));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.toggleProductUrlField);
  } else {
    window.toggleProductUrlField();
  }
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
