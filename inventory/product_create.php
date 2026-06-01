<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: login.php?msg=auth'); exit; }     // redirect RELATIVO
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

ensure_products_active_column($pdo);

$message = '';
$messageType = 'info';
$duplicateCandidates = [];
$categories = ['Bibite','Caffetteria','Colazione','Pulizia','Rosticceria'];
$units = ['pacco','cartone','blister','Bottiglia','Busta','confezione'];
$form = [
  'title' => '',
  'ean13' => '',
  'category' => $categories[0],
  'unit' => $units[0],
  'min_qty' => '0',
  'max_qty' => '0',
  'supplier_id' => '',
];

$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);


function inventory_products_redirect_url(): string {
  $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/inventory/product_create.php'));
  $scriptDir = rtrim($scriptDir, '/');
  $scriptDir = preg_replace('#(/inventory)(?:/inventory)+$#', '$1', $scriptDir) ?: '/inventory';
  if ($scriptDir === '' || $scriptDir === '.') {
    $scriptDir = '/inventory';
  }
  return $scriptDir . '/products.php';
}

function supplier_options(array $suppliers, ?int $selectedId = null): string {
  $html = '<option value="">— Nessuno —</option>';
  foreach ($suppliers as $s) {
    $sel = ($selectedId !== null && (int)$s['id'] === (int)$selectedId) ? ' selected' : '';
    $html .= '<option value="'.(int)$s['id'].'"'.$sel.'>'.htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8').'</option>';
  }
  return $html;
}

function product_similarity_key(string $value): string {
  $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
  $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
  $value = preg_replace('/\s+/u', ' ', $value);
  return trim((string)$value);
}

function product_similarity_score(string $a, string $b): float {
  $aKey = product_similarity_key($a);
  $bKey = product_similarity_key($b);
  if ($aKey === '' || $bKey === '') return 0.0;
  if ($aKey === $bKey) return 100.0;
  if (str_contains($aKey, $bKey) || str_contains($bKey, $aKey)) return 92.0;

  similar_text($aKey, $bKey, $percent);
  $lev = levenshtein($aKey, $bKey);
  $maxLen = max(strlen($aKey), strlen($bKey), 1);
  $levScore = max(0, (1 - ($lev / $maxLen)) * 100);
  return max((float)$percent, (float)$levScore);
}

function find_similar_products(PDO $pdo, string $title): array {
  $stmt = $pdo->query("SELECT id, title, category, ean13, COALESCE(is_active, 1) AS is_active FROM products ORDER BY title ASC");
  $matches = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $score = product_similarity_score($title, (string)$row['title']);
    if ($score >= 78) {
      $row['similarity'] = $score;
      $matches[] = $row;
    }
  }
  usort($matches, function($a, $b) {
    return ($b['similarity'] <=> $a['similarity']) ?: strcasecmp($a['title'], $b['title']);
  });
  return array_slice($matches, 0, 8);
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $form = array_merge($form, [
    'title' => trim($_POST['title'] ?? ''),
    'ean13' => trim($_POST['ean13'] ?? ''),
    'category' => trim($_POST['category'] ?? ''),
    'unit' => trim($_POST['unit'] ?? ''),
    'min_qty' => (string)($_POST['min_qty'] ?? '0'),
    'max_qty' => (string)($_POST['max_qty'] ?? '0'),
    'supplier_id' => trim((string)($_POST['supplier_id'] ?? '')),
  ]);

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } else {
    $title = $form['title'];
    $ean   = $form['ean13'] !== '' ? $form['ean13'] : null;
    $cat   = $form['category'];
    $unit  = $form['unit'];
    $min   = (float)$form['min_qty'];
    $max   = (float)$form['max_qty'];
    $supplier_id = $form['supplier_id'] !== '' ? (int)$form['supplier_id'] : null;
    $confirmSimilar = ($_POST['confirm_similar'] ?? '') === '1';

    if (!in_array($cat, $categories, true)) {
      $message = 'Categoria non valida.';
      $messageType = 'danger';
    } elseif (!in_array($unit, $units, true)) {
      $message = 'Unità di misura non valida.';
      $messageType = 'danger';
    } elseif (!$title) {
      $message = 'Inserire un titolo prodotto.';
      $messageType = 'danger';
    } elseif (empty($user['id'])) {
      $message = 'Sessione non valida: utente non rilevato.';
      $messageType = 'danger';
    } else {
      $duplicateCandidates = find_similar_products($pdo, $title);
      if ($duplicateCandidates && !$confirmSimilar) {
        $message = 'Attenzione: esistono già prodotti con nome uguale o simile. Verifica l’elenco e conferma solo se vuoi creare comunque il nuovo prodotto.';
        $messageType = 'warning';
      } else {
        $stmt = $pdo->prepare("\n          INSERT INTO products (title, ean13, category, supplier_id, unit, min_qty, max_qty, created_by)\n          VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        try {
          $stmt->execute([$title, $ean, $cat, $supplier_id, $unit, $min, $max, $user['id']]);
          header('Location: ' . inventory_products_redirect_url());
          exit;
        } catch (PDOException $e) {
          $sqlstate = $e->getCode();
          $errno    = $e->errorInfo[1] ?? 0;
          if ($sqlstate === '23000' && (int)$errno === 1062) {
            $message = 'EAN già presente (duplicato).';
          } else {
            $message = 'Errore DB: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
          }
          $messageType = 'danger';
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
        <?php if($message): ?><div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div><?php endif; ?>

        <?php if ($duplicateCandidates): ?>
          <div class="alert alert-warning">
            <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Prodotti simili trovati</div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Prodotto esistente</th><th>Categoria</th><th>EAN</th><th>Stato</th><th>Similarità</th></tr></thead>
                <tbody>
                  <?php foreach ($duplicateCandidates as $candidate): ?>
                    <tr>
                      <td><?= e($candidate['title']) ?></td>
                      <td><?= e($candidate['category'] ?? '') ?></td>
                      <td><?= e($candidate['ean13'] ?: '—') ?></td>
                      <td><?= ((int)$candidate['is_active'] === 1) ? '<span class="badge text-bg-success">Attivo</span>' : '<span class="badge text-bg-secondary">Non attivo</span>' ?></td>
                      <td><?= e((string)round((float)$candidate['similarity'])) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <?php if ($duplicateCandidates): ?><input type="hidden" name="confirm_similar" value="1"><?php endif; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Titolo</label>
              <input type="text" name="title" class="form-control" value="<?= e($form['title']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">EAN 13 (opzionale)</label>
              <input type="text" name="ean13" class="form-control" maxlength="13" pattern="\d{13}" placeholder="13 cifre" value="<?= e($form['ean13']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Categoria</label>
              <select name="category" class="form-select">
                <?php foreach($categories as $c): ?><option value="<?= e($c) ?>" <?= $form['category']===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Unità</label>
              <select name="unit" class="form-select">
                <?php foreach($units as $u): ?><option value="<?= e($u) ?>" <?= $form['unit']===$u?'selected':'' ?>><?= e($u) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Min</label>
              <input type="number" step="0.001" name="min_qty" class="form-control" value="<?= e($form['min_qty']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">Max</label>
              <input type="number" step="0.001" name="max_qty" class="form-control" value="<?= e($form['max_qty']) ?>">
            </div>
             <div class="col-md-4">
              <label class="form-label">Fornitore</label>
               <select name="supplier_id" class="form-select">
                <?= supplier_options($suppliers, $form['supplier_id'] !== '' ? (int)$form['supplier_id'] : null) ?>
                </select>
            </div>
          </div>
          <div class="mt-3 d-flex flex-wrap gap-2">
            <button class="btn <?= $duplicateCandidates ? 'btn-warning' : 'btn-primary' ?>"><?= $duplicateCandidates ? 'Conferma inserimento' : 'Salva' ?></button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/inventory/products.php">Annulla</a>
          </div>
          <?php if ($duplicateCandidates): ?>
            <div class="form-text mt-2">Premendo “Conferma inserimento” il prodotto verrà creato anche se sono presenti nomi simili. Con “Annulla” torni all’elenco senza salvare.</div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
