<?php
// dashboard.php — riepilogo rapido (prossimi 5 Task, Transfer Interni, Transfer Esterni)
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env   = require __DIR__ . '/config/env.php';
$base  = rtrim($env['app']['base_url'] ?? '', '/');
$pdo   = db();
$user  = current_user();

if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

// Ruolo e dipartimento
$st = $pdo->prepare('SELECT role, dipartimento FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$me = $st->fetch();
$is_admin = ($me['role'] ?? '') === 'admin';
$my_dep   = $me['dipartimento'] ?? null;

// --- Prossimi 5 TASK (stato aperto) ---
// Admin: tutti; Non admin: solo del proprio dipartimento
if ($is_admin) {
  $qTasks = 'SELECT id, title, priority, dipartimento, due_date
             FROM tasks
             WHERE deleted_at IS NULL AND status = "aperto"
             ORDER BY due_date ASC, FIELD(priority,"urgente","alta","media","bassa") ASC, id DESC
             LIMIT 5';
  $tasks = $pdo->query($qTasks)->fetchAll();
} else {
  $qTasks = 'SELECT id, title, priority, dipartimento, due_date
             FROM tasks
             WHERE deleted_at IS NULL AND status = "aperto" AND dipartimento = ?
             ORDER BY due_date ASC, FIELD(priority,"urgente","alta","media","bassa") ASC, id DESC
             LIMIT 5';
  $stt = $pdo->prepare($qTasks);
  $stt->execute([$my_dep]);
  $tasks = $stt->fetchAll();
}

// --- Prossimi 5 TRANSFER INTERNI ---
$qInt = $pdo->prepare('SELECT id, room_number, direction, location, when_at
                       FROM transfers_internal
                       WHERE deleted_at IS NULL AND when_at >= NOW()
                       ORDER BY when_at ASC, id DESC
                       LIMIT 5');
$qInt->execute();
$tin = $qInt->fetchAll();

// --- Prossimi 5 TRANSFER ESTERNI ---
$qExt = $pdo->prepare('SELECT id, type, place, date_time, pickup_time, room_number, guest_name, booked, paid, status
                       FROM transfers_external
                       WHERE deleted_at IS NULL AND date_time >= NOW()
                       ORDER BY date_time ASC, id DESC
                       LIMIT 5');
$qExt->execute();
$tex = $qExt->fetchAll();

$title = 'Dashboard';
include __DIR__ . '/partials/header.php';

// Helpers per badge
function badge_priority($p){
  $map = ['bassa'=>'secondary','media'=>'primary','alta'=>'warning','urgente'=>'danger'];
  $lbl = ucfirst($p);
  $cls = $map[$p] ?? 'secondary';
  return '<span class="badge bg-'.$cls.'">'.$lbl.'</span>';
}
function it_date($ymd){ // Y-m-d -> d/m/y
  if(!$ymd) return '';
  $d = DateTime::createFromFormat('Y-m-d', $ymd);
  return $d ? $d->format('d/m/y') : htmlspecialchars($ymd);
}
function it_dt($dts){ // Y-m-d H:i:s -> d/m/y H:i
  if(!$dts) return '';
  $d = new DateTime($dts);
  return $d->format('d/m/y H:i');
}
?>
<div class="row g-4">

  <!-- BOX TASK -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-list-check me-1"></i> Prossimi Task</h2>
          <a href="<?= e($base) ?>/tasks.php" class="small text-decoration-none">Vedi tutti</a>
        </div>
        <?php if(empty($tasks)): ?>
          <div class="text-muted small">Nessun task imminente.</div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach($tasks as $t): ?>
              <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                <div class="me-2">
                  <div class="fw-semibold"><?= e($t['title']) ?></div>
                  <div class="small text-muted">
                    Scad.: <?= it_date($t['due_date']) ?> · Dip.: <?= e($t['dipartimento']) ?>
                  </div>
                </div>
                <div><?= badge_priority($t['priority']) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- BOX TRANSFER INTERNI -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-building-check me-1"></i> Transfer Interni</h2>
          <a href="<?= e($base) ?>/transfers_internal.php" class="small text-decoration-none">Vedi tutti</a>
        </div>
        <?php if(empty($tin)): ?>
          <div class="text-muted small">Nessun transfer interno imminente.</div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach($tin as $r): ?>
              <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                <div class="me-2">
                  <div class="fw-semibold">
                    Cam. <?= e($r['room_number']) ?> · <?= e(strtoupper($r['direction'])) ?> <?= e($r['location']) ?>
                  </div>
                  <div class="small text-muted">
                    <?= it_dt($r['when_at']) ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- BOX TRANSFER ESTERNI -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-bus-front me-1"></i> Transfer Esterni</h2>
          <a href="<?= e($base) ?>/transfers_external.php" class="small text-decoration-none">Vedi tutti</a>
        </div>
        <?php if(empty($tex)): ?>
          <div class="text-muted small">Nessun transfer esterno imminente.</div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach($tex as $r): ?>
              <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                <div class="me-2">
                  <div class="fw-semibold">
                    <?= e(ucfirst($r['type'])) ?> · <?= e($r['place'] ?? '') ?> · Cam. <?= e($r['room_number']) ?>
                  </div>
                  <div class="small text-muted">
                    <?= it_dt($r['date_time']) ?> · Pickup <?= e(substr($r['pickup_time'],0,5)) ?> · <?= e($r['guest_name']) ?>
                  </div>
                </div>
                <div class="text-nowrap small">
                  <?= ($r['booked'] ? '<span class="badge bg-primary">Pren.</span>' : '') ?>
                  <?= ($r['paid']   ? '<span class="badge bg-success ms-1">Pag.</span>' : '') ?>
                  <?= (($r['status'] ?? 'attivo') === 'annullato' ? '<span class="badge bg-warning text-dark ms-1">Ann.</span>' : '') ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
