<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { header('Location: login.php?msg=auth'); exit; }     // redirect RELATIVO
$title = 'Magazzino';
include __DIR__ . '/../partials/header.php';

$rows = $pdo->query("
  SELECT *
  FROM (
    SELECT 
      p.id, p.title, p.unit, p.min_qty, p.max_qty,
      COALESCE(SUM(s.qty), 0) AS tot_qty
    FROM products p
    LEFT JOIN stock_levels s ON s.product_id = p.id
    GROUP BY p.id
  ) t
  WHERE t.tot_qty < t.min_qty
  ORDER BY (t.tot_qty - t.min_qty) ASC, t.title ASC
  LIMIT 100
")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Magazzino — Prodotti sotto scorta</h1>
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

<div class="card shadow-sm">
  <div class="card-body">
    <?php if(empty($rows)): ?>
      <div class="text-muted small">Nessun prodotto sotto scorta.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Prodotto</th><th>Giacenza Tot.</th><th>Min</th><th>Max</th><th>Unità</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?= e($r['title']) ?></td>
                <td><?= e((float)$r['tot_qty']) ?></td>
                <td><?= e((float)$r['min_qty']) ?></td>
                <td><?= e((float)$r['max_qty']) ?></td>
                <td><?= e($r['unit']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
