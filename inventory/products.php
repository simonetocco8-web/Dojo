<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: login.php?msg=auth'); exit; }     // redirect RELATIVO
if (!is_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Amministrazione.'; exit; }

$rows = $pdo->query("
  SELECT p.*,
    COALESCE(MAX(CASE WHEN s.warehouse='Tizzo' THEN s.qty END),0) AS tizzo_qty,
    COALESCE(MAX(CASE WHEN s.warehouse='Tramonto' THEN s.qty END),0) AS tramonto_qty
  FROM products p
  LEFT JOIN stock_levels s ON s.product_id = p.id
  GROUP BY p.id
  ORDER BY p.title ASC
")->fetchAll();

$title = 'Prodotti';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Prodotti</h1>
  <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/inventory/product_create.php">+ Nuovo Prodotto</a>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr>
          <th>Titolo</th><th>EAN13</th><th>Cat.</th><th>Unità</th>
          <th>Min</th><th>Max</th><th>Tizzo</th><th>Tramonto</th>
        </tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td><?= e($r['ean13']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td><?= e($r['unit']) ?></td>
              <td><?= e((float)$r['min_qty']) ?></td>
              <td><?= e((float)$r['max_qty']) ?></td>
              <td><?= e((float)$r['tizzo_qty']) ?></td>
              <td><?= e((float)$r['tramonto_qty']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
