<?php
// inventory/products_list.php
declare(strict_types=1);

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
$csrf = csrf_token();
$env  = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
ensure_products_active_column($pdo);
$user = current_user();

// Permessi (adatta se vuoi renderla visibile ad altri reparti in sola lettura)
if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione') || user_has_department($user, 'Bar'))) {
  http_response_code(403); exit('Permesso negato.');
}

// Config statiche
$CATEGORIES = ['Bibite','Caffetteria','Colazione','Pulizia','Rosticceria'];
$WAREHOUSES = ['Tizzo','Tramonto'];

// --- Filtri (GET) ---
$q         = trim((string)($_GET['q'] ?? ''));                // nome prodotto (LIKE)
$category  = trim((string)($_GET['category'] ?? ''));         // categoria precisa
$warehouse = trim((string)($_GET['warehouse'] ?? ''));        // Tizzo | Tramonto | ''
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

// Costruzione condizioni dinamiche (sia per COUNT che per SELECT)
$where = ["p.is_active = 1"];
$params = [];
$msg = (string)($_GET['msg'] ?? '');

// Filtro nome (LIKE)
if ($q !== '') {
  $where[] = "p.title LIKE :q";
  $params[':q'] = '%'.$q.'%';
}

// Filtro categoria
if ($category !== '' && in_array($category, $CATEGORIES, true)) {
  $where[] = "p.category = :cat";
  $params[':cat'] = $category;
}

// Filtro magazzino
$joinWarehouse = '';
if ($warehouse !== '' && in_array($warehouse, $WAREHOUSES, true)) {
  // Per filtrare per magazzino, consideriamo i prodotti che hanno stock_levels per quel warehouse
  // (anche qty=0; se vuoi solo quelli con qty>0 aggiungi "AND slw.qty > 0")
  $joinWarehouse = "JOIN stock_levels slw ON slw.product_id = p.id AND slw.warehouse = :wh";
  $params[':wh'] = $warehouse;
}

// WHERE finale
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// --- COUNT totale risultati (senza LIMIT), usando DISTINCT su prodotti ---
$countSql = "
  SELECT COUNT(*) AS total
  FROM (
    SELECT DISTINCT p.id
    FROM products p
    $joinWarehouse
    $whereSql
  ) t
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// --- SELECT pagina corrente con aggregati di giacenza ---
$listSql = "
  SELECT 
    p.id,
    p.title,
    p.ean13,
    p.category,
    p.unit,
    p.min_qty,
    p.max_qty,
    s.name AS supplier_name,
    COALESCE(SUM(sl.qty), 0) AS total_qty,
    COALESCE(SUM(CASE WHEN sl.warehouse = 'Tizzo' THEN sl.qty ELSE 0 END), 0)    AS qty_tizzo,
    COALESCE(SUM(CASE WHEN sl.warehouse = 'Tramonto' THEN sl.qty ELSE 0 END), 0) AS qty_tramonto
  FROM products p
  LEFT JOIN stock_levels sl ON sl.product_id = p.id
  LEFT JOIN suppliers s ON s.id = p.supplier_id
  $joinWarehouse
  $whereSql
  GROUP BY p.id, p.title, p.ean13, p.category, p.unit, p.min_qty, p.max_qty, s.name
  ORDER BY p.title ASC
  LIMIT :limit OFFSET :offset
";
$listStmt = $pdo->prepare($listSql);

// bind dinamici
foreach ($params as $k => $v) {
  $listStmt->bindValue($k, $v);
}
// bind per pagina
$listStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Prodotti';
include __DIR__ . '/../partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Prodotti</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="products_inactive.php">Non Attivi</a>
    <a class="btn btn-primary btn-sm" href="product_create.php">Nuovo</a>
  </div>
</div>


<?php if ($msg === 'deactivated'): ?>
  <div class="alert alert-success">Prodotto disattivato.</div>
<?php elseif ($msg === 'deleted'): ?>
  <div class="alert alert-success">Prodotto eliminato definitivamente.</div>
<?php elseif ($msg === 'error'): ?>
  <div class="alert alert-danger">Operazione non completata. Controlla il log errori.</div>
<?php endif; ?>

<!-- FILTRI -->
<form class="card mb-3" method="get">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Nome prodotto</label>
        <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Cerca per nome...">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Categoria</label>
        <select name="category" class="form-select">
          <option value="">Tutte</option>
          <?php foreach ($CATEGORIES as $c): ?>
            <option value="<?= e($c) ?>" <?= $category===$c?'selected':''; ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Magazzino</label>
        <select name="warehouse" class="form-select">
          <option value="">Tutti</option>
          <?php foreach ($WAREHOUSES as $w): ?>
            <option value="<?= e($w) ?>" <?= $warehouse===$w?'selected':''; ?>><?= e($w) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-outline-primary">Filtra</button>
      </div>
    </div>
  </div>
</form>



<!-- RISULTATI -->
<div id="stock-inline-root" data-csrf="<?= e($csrf) ?>">
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Prodotto</th>
            <th style="width:140px;">Categoria</th>
            <th style="width:110px;">UM</th>
            <th class="text-center" style="width:90px;">Min</th>
            <th class="text-center" style="width:90px;">Max</th>
            <th class="text-center" style="width:90px;">Tot</th>
            <th class="text-center" style="width:110px;">Tizzo</th>
            <th class="text-center" style="width:120px;">Tramonto</th>
            <th style="width:180px;">Fornitore</th>
            <th class="text-end" style="width:170px;">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr data-product-id="<?= (int)$r['id'] ?>">
              <td class="fw-semibold"><?= e($r['title']) ?></td>
              <td><?= e($r['category'] ?? '') ?></td>
              <td><?= e($r['unit'] ?? '') ?></td>
              <td class="text-center"><?= $r['min_qty'] !== null ? (float)$r['min_qty'] : '—' ?></td>
              <td class="text-center"><?= $r['max_qty'] !== null ? (float)$r['max_qty'] : '—' ?></td>

              <!-- Totale: verrà aggiornato dal JS, con rosso se sotto scorta -->
              <?php
                $tot = (float)$r['total_qty'];
                $min = ($r['min_qty'] !== null) ? (float)$r['min_qty'] : null;
                $isLow = ($min !== null && $tot < $min);
              ?>
              <td class="text-center">
                  <span class="badge <?= ((float)$r['total_qty'] < (float)($r['min_qty'] ?? 0)) ? 'bg-danger' : 'bg-light text-dark' ?>"
                        data-total-for="<?= (int)$r['id'] ?>"
                        data-min="<?= $r['min_qty']!==null ? (float)$r['min_qty'] : '' ?>">
                    <?= (float)$r['total_qty'] ?>
                  </span>
                </td>

              <!-- Tizzo inline-edit -->
              <td class="text-center">
                  <input type="number" step="1"
                         class="form-control form-control-sm stock-input"
                         value="<?= (float)$r['qty_tizzo'] ?>"
                         data-product-id="<?= (int)$r['id'] ?>"
                         data-warehouse="Tizzo">
                </td>
                <td class="text-center">
                  <input type="number" step="1"
                         class="form-control form-control-sm stock-input"
                         value="<?= (float)$r['qty_tramonto'] ?>"
                         data-product-id="<?= (int)$r['id'] ?>"
                         data-warehouse="Tramonto">
                </td>

              <td><?= $r['supplier_name'] ? e($r['supplier_name']) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-link btn-sm" href="product_edit.php?id=<?= (int)$r['id'] ?>" title="Modifica">✏️</a>
                <form method="post" action="<?= e($base) ?>/inventory/product_action.php" class="d-inline" data-confirm-message="Disattivare questo prodotto? Non sarà più visibile nella lista principale.">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="deactivate">
                  <button class="btn btn-link btn-sm text-warning" title="Disattiva" aria-label="Disattiva"><i class="bi bi-pause-circle"></i></button>
                </form>
                <form method="post" action="<?= e($base) ?>/inventory/product_action.php" class="d-inline" data-confirm-message="Eliminare definitivamente questo prodotto dal sistema?">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-link btn-sm text-danger" title="Elimina definitivamente" aria-label="Elimina definitivamente"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="11" class="text-center text-muted py-3">Nessun prodotto trovato</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>/assets/products_inline_stock.js" defer></script>

<!-- PAGINAZIONE -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm mb-0">
    <?php
      // costruiamo la base querystring senza 'page'
      $qs = $_GET; unset($qs['page']);
      $baseQs = http_build_query($qs);
      $link = function(int $p) use ($baseQs) {
        return '?'.($baseQs ? $baseQs.'&' : '').'page='.$p;
      };
    ?>
    <li class="page-item <?= $page<=1?'disabled':'' ?>">
      <a class="page-link" href="<?= $page>1 ? e($link($page-1)) : '#' ?>" tabindex="-1">«</a>
    </li>

    <?php
      // pagine (compatte): mostra le prime, le ultime, e l'intorno
      $window = 2;
      $printedDotsLeft = false;
      $printedDotsRight = false;
      for ($p=1; $p<=$totalPages; $p++) {
        $isEdge = ($p<=2) || ($p>$totalPages-2);
        $isNear = abs($p - $page) <= $window;
        if ($isEdge || $isNear) {
          echo '<li class="page-item '.($p===$page?'active':'').'"><a class="page-link" href="'.e($link($p)).'">'.$p.'</a></li>';
        } else {
          if ($p < $page && !$printedDotsLeft) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $printedDotsLeft=true; }
          if ($p > $page && !$printedDotsRight) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $printedDotsRight=true; }
        }
      }
    ?>

    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
      <a class="page-link" href="<?= $page<$totalPages ? e($link($page+1)) : '#' ?>">»</a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
