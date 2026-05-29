<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
ensure_task_user_assignments_table($pdo);

$stmt = $pdo->prepare('SELECT dipartimento, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();
$my_deps = user_departments($me);
$my_dep = $my_deps[0] ?? null;
$myDepPlaceholders = $my_deps ? implode(',', array_fill(0, count($my_deps), '?')) : "''";
$is_admin = ($me['role'] ?? '') === 'admin';
$myTaskCondition = '((NOT EXISTS (SELECT 1 FROM task_user_assignments tv WHERE tv.task_id = t.id) AND t.dipartimento IN ('.$myDepPlaceholders.')) OR tua_me.user_id IS NOT NULL)';

$msg = $_GET['msg'] ?? '';
$view = $_GET['view'] ?? 'mio';
$allowedViews = ['mio','tutti','completati','nonfattibili','cestino'];
if (!in_array($view, $allowedViews, true)) $view = 'mio';

$where = [];
$args = [$user['id']];

if ($view === 'mio') {
  $where[] = 't.deleted_at IS NULL AND t.status IN ("aperto") AND (' . $myTaskCondition . ')';
  $args = array_merge($args, $my_deps);
} elseif ($view === 'tutti') {
  if ($is_admin) {
    $where[] = 't.deleted_at IS NULL AND t.status!="completato"';
  } else {
    $where[] = 't.deleted_at IS NULL AND (' . $myTaskCondition . ') AND t.status!="completato"';
    $args = array_merge($args, $my_deps);
  }
} elseif ($view === 'completati') {
  $where[] = 't.deleted_at IS NULL AND t.status="completato"';
  if (!$is_admin) { $where[] = '(' . $myTaskCondition . ')'; $args = array_merge($args, $my_deps); }
} elseif ($view === 'nonfattibili') {
  $where[] = 't.deleted_at IS NULL AND t.status="non_fattibile"';
  if (!$is_admin) { $where[] = '(' . $myTaskCondition . ')'; $args = array_merge($args, $my_deps); }
} elseif ($view === 'cestino') {
  if (!$is_admin) { header('Location: ' . $base . '/tasks.php'); exit; }
  $where[] = 't.deleted_at IS NOT NULL';
}

$sql = "SELECT t.*, u.email AS created_by_email,
               (SELECT GROUP_CONCAT(tua_ids.user_id ORDER BY tua_ids.user_id SEPARATOR ',')
                FROM task_user_assignments tua_ids
                WHERE tua_ids.task_id = t.id) AS assigned_user_ids,
               (SELECT GROUP_CONCAT(TRIM(CONCAT(COALESCE(au.cognome, ''), ' ', COALESCE(au.nome, ''))) ORDER BY au.cognome, au.nome SEPARATOR ', ')
                FROM task_user_assignments tua_names
                JOIN users au ON au.id = tua_names.user_id
                WHERE tua_names.task_id = t.id) AS assigned_user_names
        FROM tasks t
        JOIN users u ON u.id = t.created_by
        LEFT JOIN task_user_assignments tua_me ON tua_me.task_id = t.id AND tua_me.user_id = ?
        " . (count($where) ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY t.due_date ASC, FIELD(t.priority,'urgente','alta','media','bassa') ASC, t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$tasks = $stmt->fetchAll();

$title = 'Cose da Fare';
include __DIR__ . '/partials/header.php';

function badge_priority($p){
  $map = ['bassa'=>'secondary','media'=>'primary','alta'=>'warning','urgente'=>'danger'];
  $lbl = ucfirst($p);
  $cls = $map[$p] ?? 'secondary';
  return '<span class="badge bg-'.$cls.'">'.$lbl.'</span>';
}
function badge_status($s){
  if ($s==='aperto') return '<span class="badge bg-info">Aperto</span>';
  if ($s==='completato') return '<span class="badge bg-success">Completato</span>';
  if ($s==='non_fattibile') return '<span class="badge bg-dark">Non fattibile</span>';
  return '<span class="badge bg-secondary">'.htmlspecialchars($s).'</span>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Cose da Fare</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/task_create.php">+ Nuovo compito</a>
  </div>
</div>

<?php if ($msg === 'created'): ?>
  <div class="alert alert-success">Task creato e SMS inviato ai destinatari.</div>
<?php elseif (strpos($msg, 'created_sms_error:') === 0): ?>
  <div class="alert alert-warning">Task creato, ma SMS non inviato: <?= e(substr($msg, strlen('created_sms_error:'))) ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $view==='mio'?'active':'' ?>" href="<?= e($base) ?>/tasks.php?view=mio">Assegnati a me / ai miei dipartimenti</a></li>
  <li class="nav-item"><a class="nav-link <?= $view==='tutti'?'active':'' ?>" href="<?= e($base) ?>/tasks.php?view=tutti">Tutti<?= $is_admin?' (admin)':'' ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $view==='completati'?'active':'' ?>" href="<?= e($base) ?>/tasks.php?view=completati">Completati</a></li>
  <li class="nav-item"><a class="nav-link <?= $view==='nonfattibili'?'active':'' ?>" href="<?= e($base) ?>/tasks.php?view=nonfattibili">Non fattibili</a></li>
  <?php if($is_admin): ?>
  <li class="nav-item"><a class="nav-link <?= $view==='cestino'?'active':'' ?>" href="<?= e($base) ?>/tasks.php?view=cestino">Cestino</a></li>
  <?php endif; ?>
</ul>






<div class="card shadow-sm">
  <div class="card-body">
      
      
      
       <!-- Leggenda icone -->
    <div class="d-flex justify-content-end mb-2">
      <div class="small text-muted">
        <i class="bi bi-check2-circle text-success"></i> Completa &nbsp;|&nbsp;
        <i class="bi bi-slash-circle text-dark"></i> Non fattibile &nbsp;|&nbsp;
        <i class="bi bi-trash text-danger"></i> Cestina &nbsp;|&nbsp;
        <i class="bi bi-arrow-counterclockwise text-secondary"></i> Ripristina
      </div>
    </div>
    
    
    
    
    
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Scadenza</th>
            <th>Priorità</th>
            <th>Destinatari</th>
            <th>Titolo</th>
            <th>Descrizione</th>
            <th>Ricorrenza</th>
            <th>Stato</th>
            <th>Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($tasks as $t): ?>
          <?php
            $assignedIds = array_filter(explode(',', (string)($t['assigned_user_ids'] ?? '')));
            $hasAssignedUsers = !empty($assignedIds);
            $canAct = $is_admin || (!$hasAssignedUsers && in_array($t['dipartimento'], $my_deps, true)) || in_array((string)$user['id'], $assignedIds, true);
            $recipientLabel = !empty($t['assigned_user_names']) ? $t['assigned_user_names'] : $t['dipartimento'];
          ?>
          <tr>
            <td>
              <?php
                // Format data in italiano
                if (!empty($t['due_date'])) {
                  $d = DateTime::createFromFormat('Y-m-d', $t['due_date']);
                  $formatted = $d ? $d->format('d/m/Y') : $t['due_date'];
                } else {
                  $formatted = '';
                }
              ?>
              <div class="d-flex flex-column gap-1">
                <div class="d-flex align-items-center gap-2">
                  <span><?= $formatted ? e($formatted) : '-' ?></span>
                  <?php if($canAct && $t['deleted_at']===null): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary" title="Modifica scadenza"
                          data-bs-toggle="collapse" data-bs-target="#due_<?= (int)$t['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <?php endif; ?>
                </div>
                <?php if($canAct && $t['deleted_at']===null): ?>
                <div id="due_<?= (int)$t['id'] ?>" class="collapse">
                  <form method="post" action="<?= e($base) ?>/task_status.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="action" value="update_due_date">
                    <input type="date" name="due_date" value="<?= e($t['due_date']) ?>" class="form-control form-control-sm" required>
                    <button class="btn btn-sm btn-primary" title="Salva"><i class="bi bi-check"></i></button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
            </td>
            <td><?= badge_priority($t['priority']) ?></td>
            <td><?= e($recipientLabel) ?></td>
            <td class="fw-semibold"><?= e($t['title']) ?></td>
            <td class="small"><?= nl2br(e($t['description'])) ?></td>
            <td><?= e(ucfirst($t['recurrence'])) ?></td>
            <td><?= badge_status($t['status']) ?></td>
           <td class="text-nowrap">
              <?php
                if ($t['status']==='aperto' && $canAct):
              ?>
                <!-- Completa -->
                <form method="post" action="<?= e($base) ?>/task_status.php" class="d-inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="action" value="complete">
                  <button class="btn btn-sm btn-outline-success" title="Completa">
                    <i class="bi bi-check2-circle"></i>
                  </button>
                </form>
            
                <!-- Non fattibile -->
                <button class="btn btn-sm btn-outline-dark" title="Non fattibile"
                        data-bs-toggle="collapse" data-bs-target="#nf_<?= (int)$t['id'] ?>">
                  <i class="bi bi-slash-circle"></i>
                </button>
                <div id="nf_<?= (int)$t['id'] ?>" class="collapse mt-2">
                  <form method="post" action="<?= e($base) ?>/task_status.php" class="d-flex gap-2">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="action" value="nonfattibile">
                    <input type="text" name="status_note" class="form-control form-control-sm"
                           placeholder="Motivo..." required>
                    <button class="btn btn-sm btn-dark"><i class="bi bi-send"></i></button>
                  </form>
                </div>
              <?php endif; ?>
            
              <?php if($is_admin && $t['deleted_at']===null): ?>
                <!-- Cestina -->
                <form method="post" action="<?= e($base) ?>/task_status.php" class="d-inline ms-1"
                      onsubmit="return confirm('Spostare nel cestino questo compito?');">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="action" value="trash">
                  <button class="btn btn-sm btn-outline-danger" title="Cestina">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              <?php elseif($is_admin && $t['deleted_at']!==null): ?>
                <!-- Ripristina -->
                <form method="post" action="<?= e($base) ?>/task_status.php" class="d-inline ms-1">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="action" value="restore">
                  <button class="btn btn-sm btn-outline-secondary" title="Ripristina">
                    <i class="bi bi-arrow-counterclockwise"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
