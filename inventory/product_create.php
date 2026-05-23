<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: login.php?msg=auth'); exit; }     // redirect RELATIVO
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$message = '';
$categories = ['Bibite','Caffetteria','Colazione','Pulizia','Rosticceria'];
$units = ['pacco','cartone','blister','Bottiglia','Busta','confezione'];


$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function supplier_options(array $suppliers, ?int $selectedId = null): string {
  $html = '<option value="">— Nessuno —</option>';
  foreach ($suppliers as $s) {
    $sel = ($selectedId !== null && (int)$s['id'] === (int)$selectedId) ? ' selected' : '';
    $html .= '<option value="'.(int)$s['id'].'"'.$sel.'>'.htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8').'</option>';
  }
  return $html;
}

// ... in cima al file già hai require e $categories / $units ...

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $title = trim($_POST['title'] ?? '');
    $ean   = trim($_POST['ean13'] ?? '');
    $ean   = ($ean !== '') ? $ean : null; // importante: NULL se vuoto
    $cat   = trim($_POST['category'] ?? '');
    $unit  = trim($_POST['unit'] ?? '');
    $min   = (float)($_POST['min_qty'] ?? 0);
    $max   = (float)($_POST['max_qty'] ?? 0);
    $supplier_id = trim($_POST['supplier_id'] ?? null); 


    // Normalizza/valida enum esattamente come in DB
    if (!in_array($cat, $categories, true)) {
      $message = 'Categoria non valida.';
    } elseif (!in_array($unit, $units, true)) {
      $message = 'Unità di misura non valida.';
    } elseif (!$title) {
      $message = 'Inserire un titolo prodotto.';
    } elseif (empty($user['id'])) {
      $message = 'Sessione non valida: utente non rilevato.';
    } else {
      $stmt = $pdo->prepare("
        INSERT INTO products (title, ean13, category, supplier_id, unit, min_qty, max_qty, created_by)
        VALUES (?,?,?,?,?,?,?,?)
      ");
      try {
        $stmt->execute([$title, $ean, $cat, $supplier_id, $unit, $min, $max, $user['id']]);
        header('Location: ' . ($base ? $base.'/' : '') . 'inventory/products.php');
        exit;
      } catch (PDOException $e) {
        $sqlstate = $e->getCode();          // es. '23000'
        $errno    = $e->errorInfo[1] ?? 0;  // es. 1062 per duplicate key
        if ($sqlstate === '23000' && (int)$errno === 1062) {
          $message = 'EAN già presente (duplicato).';
        } else {
          // Mostra errore reale per capire la causa (poi potrai renderlo più “friendly”)
          $message = 'Errore DB: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
      }
    }
  }
}

$title = 'Nuovo Prodotto';
include __DIR__ . '/../partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h6 mb-3">Nuovo Prodotto</h1>
        <?php if($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Titolo</label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">EAN 13 (opzionale)</label>
              <input type="text" name="ean13" class="form-control" maxlength="13" pattern="\d{13}" placeholder="13 cifre">
            </div>
            <div class="col-md-4">
              <label class="form-label">Categoria</label>
              <select name="category" class="form-select">
                <?php foreach($categories as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Unità</label>
              <select name="unit" class="form-select">
                <?php foreach($units as $u): ?><option value="<?= e($u) ?>"><?= e($u) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Min</label>
              <input type="number" step="0.001" name="min_qty" class="form-control" value="0">
            </div>
            <div class="col-md-2">
              <label class="form-label">Max</label>
              <input type="number" step="0.001" name="max_qty" class="form-control" value="0">
            </div>
             <div class="col-md-4">
              <label class="form-label">Fornitore</label>
               <select name="supplier_id" class="form-select">
                <?= supplier_options($suppliers, null) ?>
                </select>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Salva</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/inventory/products.php">Annulla</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
