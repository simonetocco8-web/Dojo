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
<div class="row g-4 mb-4">

  <!-- BOX TASK -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-list-check me-1"></i> Prossimi Task</h2>
             <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/tasks.php" title="Vai alla sezione">Apri</a>
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
<?php  if($my_dep!="Manutenzione"){ ?>
  <!-- BOX TRANSFER INTERNI -->
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-building-check me-1"></i> Transfer Interni</h2>
          <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/transfers_internal.php" title="Vai alla sezione">Apri</a>
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
          <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/transfers_external.php" title="Vai alla sezione">Apri</a>
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
  <?php } ?>
  
</div>  
<div class="row g-4">  
  <?php
// --- BOX: Giorni liberi prossimi 7 giorni ---
if ($user && (is_admin() || (($user['dipartimento'] ?? '') === 'Amministrazione'))) {
  $tz = new DateTimeZone('Europe/Rome');
  $today = (new DateTime('today', $tz))->format('Y-m-d');
  $to7   = (new DateTime('today', $tz))->modify('+7 days')->format('Y-m-d');

  $stmt = $pdo->prepare("
    SELECT d.day, d.note,
           u.nome, u.cognome, u.dipartimento
    FROM days_off d
    JOIN users u ON u.id = d.user_id
    WHERE d.deleted_at IS NULL
      AND d.day BETWEEN ? AND ?
    ORDER BY d.day ASC, u.cognome ASC, u.nome ASC
    LIMIT 50
  ");
  $stmt->execute([$today, $to7]);
  $daysOffNext = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="col-12 col-lg-4">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-calendar-range me-1"></i>Giorni liberi (prox. 7 giorni)</h2>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/days_off_list.php" title="Vai alla sezione">Apri</a>
      </div>

      <?php if (empty($daysOffNext)): ?>
        <div class="text-muted small">Nessun giorno libero pianificato nei prossimi 7 giorni.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th style="width:110px;">Data</th>
                <th>Utente</th>
                <th style="width:140px;">Dip.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($daysOffNext as $r): ?>
                <tr>
                  <td><?= (new DateTime($r['day'], $tz))->format('d/m/Y') ?></td>
                  <td><?= e(trim(($r['cognome'] ?? '').' '.($r['nome'] ?? ''))) ?></td>
                  <td><span class="badge bg-light text-dark"><?= e($r['dipartimento'] ?? '') ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php } // end box Giorni liberi ?>

<?php
// --- BOX: Prodotti sottoscorta (Top 10) ---
if ($user && (is_admin() || ($user['dipartimento'] ?? '') === 'Amministrazione' || ($user['dipartimento'] ?? '') === 'Bar')) {

  $sql = "
    SELECT 
      p.id,
      p.title,
      p.category,
      p.min_qty,
      COALESCE(SUM(sl.qty), 0) AS total_qty,
      COALESCE(SUM(CASE WHEN sl.warehouse='Tizzo' THEN sl.qty ELSE 0 END), 0)    AS qty_tizzo,
      COALESCE(SUM(CASE WHEN sl.warehouse='Tramonto' THEN sl.qty ELSE 0 END), 0) AS qty_tramonto
    FROM products p
    LEFT JOIN stock_levels sl ON sl.product_id = p.id
    /* Se hai un flag attivo/inattivo, puoi aggiungere ad es.: WHERE p.active = 1 */
    GROUP BY p.id, p.title, p.category, p.min_qty
    HAVING COALESCE(SUM(sl.qty), 0) < p.min_qty
    ORDER BY total_qty ASC, p.title ASC
    LIMIT 10
  ";
  $stmt = $pdo->query($sql);
  $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="col-12 col-lg-4">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-exclamation-octagon me-1"></i>Sottoscorta (Top 10)</h2>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/inventory/index.php">Magazzino</a>
      </div>

      <?php if (empty($lowStock)): ?>
        <div class="text-muted small">Nessun prodotto sottoscorta.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Prodotto</th>
                <th class="text-center" style="width:105px;">Tot</th>
                <th class="text-center" style="width:105px;">Min</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowStock as $r): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= e($r['title']) ?></div>
                    <div class="text-muted small"><?= e($r['category'] ?? '') ?></div>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                      <?= (float)$r['total_qty'] ?>
                    </span>
                  </td>
                  <td class="text-center"><span class="badge bg-light text-dark"><?= (float)$r['min_qty'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php } // end box sottoscorta ?>

<?php
// --- BOX: Fornitori che accettano ordini oggi ---
if ($user && (is_admin() || (($user['dipartimento'] ?? '') === 'Amministrazione'))) {

  // 0=Dom, 1=Lun, ... 6=Sab
  $tz = new DateTimeZone('Europe/Rome');
  $todayIdx = (int)(new DateTime('now', $tz))->format('w');

  $sql = "
    SELECT s.id, s.name, s.phone, s.email
    FROM suppliers s
    JOIN supplier_days d
      ON d.supplier_id = s.id
     AND d.kind = 'order'
     AND d.day = :day
    ORDER BY s.name ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':day' => $todayIdx]);
  $suppliersToday = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // label giorno (opzionale, per intestazione)
  $weekdayLbl = ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'][$todayIdx];
?>
<div class="col-12 col-lg-4">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-cart-plus"></i> Ordinabili oggi (<?= e($weekdayLbl) ?>)</h2>
        <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/suppliers/suppliers_list.php">Gestisci</a>
      </div>

      <?php if (empty($suppliersToday)): ?>
        <div class="text-muted small">Nessun fornitore accetta ordini oggi.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Nome</th>
                <th style="width:140px;">Telefono</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliersToday as $s): ?>
              <tr>
                <td class="fw-semibold"><?= e($s['name']) ?></td>
                <td>
                  <?php if (!empty($s['phone'])): ?>
                    <a href="tel:<?= e($s['phone']) ?>"><?= e($s['phone']) ?></a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php } // end box fornitori oggi ?>



</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
