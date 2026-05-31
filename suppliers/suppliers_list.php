<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
$env  = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();

if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}

function weekday_labels(){ return ['Dom','Lun','Mar','Mer','Gio','Ven','Sab']; }
function render_days_badges(array $days){
  $lbl = weekday_labels(); sort($days); $out=[];
  foreach ($days as $d){ $out[]='<span class="badge bg-light text-dark me-1">'.$lbl[(int)$d].'</span>'; }
  return $out?implode(' ',$out):'<span class="text-muted">—</span>';
}

$sql = "
  SELECT s.id, s.name, s.phone, s.email
  FROM suppliers s
  ORDER BY s.name ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// carica giorni per tutti i fornitori (un colpo)
$days = $pdo->query("
  SELECT supplier_id, day, kind
  FROM supplier_days
")->fetchAll(PDO::FETCH_ASSOC);
$bySup = [];
foreach($days as $d){ $bySup[$d['supplier_id']][$d['kind']][] = (int)$d['day']; }

$title = 'Fornitori';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Fornitori</h1>
  <a class="btn btn-primary btn-sm" href="supplier_create.php">Nuovo</a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Telefono</th>
          <th>Email</th>
          <th>Giorni Ordini</th>
          <th>Giorni Consegne</th>
          <th class="text-end">Azioni</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): 
        $ord = $bySup[$r['id']]['order'] ?? [];
        $del = $bySup[$r['id']]['delivery'] ?? [];
      ?>
        <tr>
          <td class="fw-semibold"><?= e($r['name']) ?></td>
          <td><?= e($r['phone'] ?? '') ?></td>
          <td><a href="mailto:<?= e($r['email'] ?? '') ?>"><?= e($r['email'] ?? '') ?></a></td>
          <td><?= render_days_badges($ord) ?></td>
          <td><?= render_days_badges($del) ?></td>
          <td class="text-end">
            <a class="btn btn-link btn-sm" href="supplier_edit.php?id=<?= (int)$r['id'] ?>" title="Modifica">✏️</a>
            <form class="d-inline" method="post" action="supplier_delete.php" data-confirm-message="Eliminare questo fornitore?">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-link btn-sm text-danger" title="Elimina">🗑️</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-3">Nessun fornitore</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
