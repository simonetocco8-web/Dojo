<?php
require_once __DIR__ . '/_role_guard.php';

if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Bar o Amministrazione.'; exit; }

ensure_products_active_column($pdo);

$warehouses = ['Tizzo', 'Tramonto'];
$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$dateFrom = trim((string)($_GET['date_from'] ?? $defaultFrom));
$dateTo = trim((string)($_GET['date_to'] ?? $defaultTo));
$category = trim((string)($_GET['category'] ?? ''));
$productId = (int)($_GET['product_id'] ?? 0);
$warehouse = trim((string)($_GET['warehouse'] ?? ''));

$fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom);
$toDt = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo);
if (!$fromDt || $fromDt->format('Y-m-d') !== $dateFrom) {
  $dateFrom = $defaultFrom;
  $fromDt = new DateTimeImmutable($dateFrom);
}
if (!$toDt || $toDt->format('Y-m-d') !== $dateTo) {
  $dateTo = $defaultTo;
  $toDt = new DateTimeImmutable($dateTo);
}
if ($fromDt > $toDt) {
  [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
  [$fromDt, $toDt] = [$toDt, $fromDt];
}
if (!in_array($warehouse, $warehouses, true)) {
  $warehouse = '';
}

$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$products = $pdo->query("SELECT id, title, category FROM products ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$validCategories = array_map('strval', $categories);
if ($category !== '' && !in_array($category, $validCategories, true)) {
  $category = '';
}

$where = ["m.type = 'scarico'", 'm.qty_delta < 0', 'DATE(m.created_at) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($category !== '') {
  $where[] = 'p.category = ?';
  $params[] = $category;
}
if ($productId > 0) {
  $where[] = 'p.id = ?';
  $params[] = $productId;
}
if ($warehouse !== '') {
  $where[] = 'm.warehouse = ?';
  $params[] = $warehouse;
}
$whereSql = implode(' AND ', $where);

$summaryStmt = $pdo->prepare("\n  SELECT\n    COALESCE(SUM(ABS(m.qty_delta)), 0) AS total_qty,\n    COUNT(*) AS movement_count,\n    COUNT(DISTINCT p.id) AS product_count,\n    COUNT(DISTINCT COALESCE(NULLIF(p.category, ''), 'Senza categoria')) AS category_count\n  FROM stock_movements m\n  JOIN products p ON p.id = m.product_id\n  WHERE $whereSql\n");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_qty' => 0, 'movement_count' => 0, 'product_count' => 0, 'category_count' => 0];

$productStmt = $pdo->prepare("\n  SELECT\n    p.id,\n    p.title,\n    COALESCE(NULLIF(p.category, ''), 'Senza categoria') AS category,\n    p.unit,\n    COALESCE(SUM(ABS(m.qty_delta)), 0) AS total_qty,\n    COUNT(*) AS movement_count,\n    MAX(m.created_at) AS last_movement_at\n  FROM stock_movements m\n  JOIN products p ON p.id = m.product_id\n  WHERE $whereSql\n  GROUP BY p.id, p.title, p.category, p.unit\n  ORDER BY total_qty DESC, p.title ASC\n");
$productStmt->execute($params);
$productRows = $productStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->prepare("\n  SELECT\n    COALESCE(NULLIF(p.category, ''), 'Senza categoria') AS category,\n    COALESCE(SUM(ABS(m.qty_delta)), 0) AS total_qty,\n    COUNT(DISTINCT p.id) AS product_count,\n    COUNT(*) AS movement_count\n  FROM stock_movements m\n  JOIN products p ON p.id = m.product_id\n  WHERE $whereSql\n  GROUP BY COALESCE(NULLIF(p.category, ''), 'Senza categoria')\n  ORDER BY total_qty DESC, category ASC\n");
$categoryStmt->execute($params);
$categoryRows = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

$productTimelineStmt = $pdo->prepare("\n  SELECT\n    DATE_FORMAT(m.created_at, '%Y-%m') AS period_month,\n    p.title,\n    COALESCE(NULLIF(p.category, ''), 'Senza categoria') AS category,\n    p.unit,\n    COALESCE(SUM(ABS(m.qty_delta)), 0) AS total_qty\n  FROM stock_movements m\n  JOIN products p ON p.id = m.product_id\n  WHERE $whereSql\n  GROUP BY DATE_FORMAT(m.created_at, '%Y-%m'), p.id, p.title, p.category, p.unit\n  ORDER BY period_month DESC, total_qty DESC, p.title ASC\n  LIMIT 200\n");
$productTimelineStmt->execute($params);
$productTimelineRows = $productTimelineStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryTimelineStmt = $pdo->prepare("\n  SELECT\n    DATE_FORMAT(m.created_at, '%Y-%m') AS period_month,\n    COALESCE(NULLIF(p.category, ''), 'Senza categoria') AS category,\n    COALESCE(SUM(ABS(m.qty_delta)), 0) AS total_qty\n  FROM stock_movements m\n  JOIN products p ON p.id = m.product_id\n  WHERE $whereSql\n  GROUP BY DATE_FORMAT(m.created_at, '%Y-%m'), COALESCE(NULLIF(p.category, ''), 'Senza categoria')\n  ORDER BY period_month DESC, total_qty DESC, category ASC\n  LIMIT 200\n");
$categoryTimelineStmt->execute($params);
$categoryTimelineRows = $categoryTimelineStmt->fetchAll(PDO::FETCH_ASSOC);

$chartPalette = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#20c997', '#fd7e14', '#0dcaf0', '#6c757d', '#d63384'];
$categoryChartLabels = array_map(static fn($row): string => (string)$row['category'], $categoryRows);
$categoryChartValues = array_map(static fn($row): float => (float)$row['total_qty'], $categoryRows);
$categoryChartColors = array_map(static fn($index): string => $chartPalette[$index % count($chartPalette)], array_keys($categoryRows));

$timelineMonths = [];
$timelineCategories = [];
$timelineValues = [];
foreach ($categoryTimelineRows as $row) {
  $month = (string)$row['period_month'];
  $rowCategory = (string)$row['category'];
  $timelineMonths[$month] = true;
  $timelineCategories[$rowCategory] = true;
  $timelineValues[$rowCategory][$month] = (float)$row['total_qty'];
}
$timelineLabels = array_keys($timelineMonths);
sort($timelineLabels);
$timelineCategoryNames = array_keys($timelineCategories);
sort($timelineCategoryNames, SORT_NATURAL | SORT_FLAG_CASE);
$categoryTimelineDatasets = [];
foreach ($timelineCategoryNames as $index => $rowCategory) {
  $color = $chartPalette[$index % count($chartPalette)];
  $categoryTimelineDatasets[] = [
    'label' => $rowCategory,
    'data' => array_map(static fn($month): float => $timelineValues[$rowCategory][$month] ?? 0.0, $timelineLabels),
    'borderColor' => $color,
    'backgroundColor' => $color,
    'tension' => 0.3,
    'pointRadius' => 3,
    'pointHoverRadius' => 5,
    'fill' => false,
  ];
}

$formatQty = static function ($value): string {
  return number_format((float)$value, 2, ',', '.');
};

$title = 'Statistiche Magazzino';
include __DIR__ . '/../partials/header.php';
?>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
  <div>
    <h1 class="h4 mb-1">Statistiche Magazzino</h1>
    <div class="text-muted small">Analisi delle quantità scaricate nel tempo per prodotto e categoria.</div>
  </div>
  <div class="d-flex flex-wrap gap-2 no-print">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/inventory/products.php">Prodotti</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/inventory/carico.php">Carico</a>
    <a class="btn btn-sm btn-outline-success" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a>
  </div>
</div>

<form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-3 col-xl-2">
        <label class="form-label">Dal</label>
        <input type="date" class="form-control" name="date_from" value="<?= e($dateFrom) ?>">
      </div>
      <div class="col-12 col-md-3 col-xl-2">
        <label class="form-label">Al</label>
        <input type="date" class="form-control" name="date_to" value="<?= e($dateTo) ?>">
      </div>
      <div class="col-12 col-md-3 col-xl-2">
        <label class="form-label">Magazzino</label>
        <select name="warehouse" class="form-select">
          <option value="">Tutti</option>
          <?php foreach ($warehouses as $wh): ?>
            <option value="<?= e($wh) ?>" <?= $warehouse === $wh ? 'selected' : '' ?>><?= e($wh) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3 col-xl-2">
        <label class="form-label">Categoria</label>
        <select name="category" class="form-select">
          <option value="">Tutte</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $category === (string)$cat ? 'selected' : '' ?>><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-xl-3">
        <label class="form-label">Prodotto</label>
        <select name="product_id" class="form-select">
          <option value="0">Tutti</option>
          <?php foreach ($products as $product): ?>
            <option value="<?= (int)$product['id'] ?>" <?= $productId === (int)$product['id'] ? 'selected' : '' ?>>
              <?= e($product['title']) ?><?= !empty($product['category']) ? ' — ' . e($product['category']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-xl-1">
        <button class="btn btn-primary w-100">Filtra</button>
      </div>
    </div>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-12 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Quantità scaricata</div><div class="fs-4 fw-semibold"><?= e($formatQty($summary['total_qty'] ?? 0)) ?></div></div></div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Movimenti scarico</div><div class="fs-4 fw-semibold"><?= (int)($summary['movement_count'] ?? 0) ?></div></div></div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Prodotti coinvolti</div><div class="fs-4 fw-semibold"><?= (int)($summary['product_count'] ?? 0) ?></div></div></div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Categorie coinvolte</div><div class="fs-4 fw-semibold"><?= (int)($summary['category_count'] ?? 0) ?></div></div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-7">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Scarichi per prodotto</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Prodotto</th><th>Categoria</th><th class="text-end">Quantità scaricata</th><th class="text-end">Movimenti</th><th>Ultimo scarico</th></tr></thead>
            <tbody>
              <?php foreach ($productRows as $row): ?>
                <tr>
                  <td><?= e($row['title']) ?><?= !empty($row['unit']) ? '<div class="text-muted small">' . e($row['unit']) . '</div>' : '' ?></td>
                  <td><?= e($row['category']) ?></td>
                  <td class="text-end fw-semibold"><?= e($formatQty($row['total_qty'])) ?></td>
                  <td class="text-end"><?= (int)$row['movement_count'] ?></td>
                  <td><?= !empty($row['last_movement_at']) ? e((new DateTime($row['last_movement_at']))->format('d/m/Y H:i')) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$productRows): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">Nessuno scarico trovato per i filtri selezionati.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Scarichi per categoria</h2>
        <?php if ($categoryRows): ?>
          <div class="inventory-chart-wrap inventory-chart-wrap--pie">
            <canvas id="categoryPieChart"
                    aria-label="Grafico a torta degli scarichi per categoria"
                    role="img"
                    data-chart-labels="<?= e(json_encode($categoryChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    data-chart-values="<?= e(json_encode($categoryChartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    data-chart-colors="<?= e(json_encode($categoryChartColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"></canvas>
          </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">Nessuno scarico trovato.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-7">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Andamento mensile per prodotto</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Mese</th><th>Prodotto</th><th>Categoria</th><th class="text-end">Quantità scaricata</th></tr></thead>
            <tbody>
              <?php foreach ($productTimelineRows as $row): ?>
                <tr>
                  <td><?= e($row['period_month']) ?></td>
                  <td><?= e($row['title']) ?><?= !empty($row['unit']) ? '<div class="text-muted small">' . e($row['unit']) . '</div>' : '' ?></td>
                  <td><?= e($row['category']) ?></td>
                  <td class="text-end fw-semibold"><?= e($formatQty($row['total_qty'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$productTimelineRows): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Nessun andamento disponibile.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6 mb-3">Andamento mensile per categoria</h2>
        <?php if ($categoryTimelineRows): ?>
          <div class="inventory-chart-wrap inventory-chart-wrap--line">
            <canvas id="categoryTimelineChart"
                    aria-label="Grafico a linee dell'andamento mensile per categoria"
                    role="img"
                    data-chart-labels="<?= e(json_encode($timelineLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    data-chart-datasets="<?= e(json_encode($categoryTimelineDatasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"></canvas>
          </div>
        <?php else: ?>
          <div class="text-center text-muted py-5">Nessun andamento disponibile.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($categoryRows || $categoryTimelineRows): ?>
  <?php $inventoryStatisticsChartsVersion = @filemtime(__DIR__ . '/../assets/inventory_statistics_charts.js') ?: time(); ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script src="<?= e($base) ?>/assets/inventory_statistics_charts.js?v=<?= (int)$inventoryStatisticsChartsVersion ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
