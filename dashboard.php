<?php
// dashboard.php — riepilogo rapido (prossimi 5 Task, Transfer Interni, Transfer Esterni)
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/settings.php';

start_session();
$env   = require __DIR__ . '/config/env.php';
$base  = rtrim($env['app']['base_url'] ?? '', '/');
$pdo   = db();
$user  = current_user();
$seasonActive = is_today_within_summer_season($pdo);

if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }
ensure_task_user_assignments_table($pdo);
ensure_products_active_column($pdo);
ensure_riassetti_status_column($pdo);

// Ruolo e dipartimento
$st = $pdo->prepare('SELECT role, dipartimento FROM users WHERE id = ? LIMIT 1');
$st->execute([$user['id']]);
$me = $st->fetch();
$is_admin = ($me['role'] ?? '') === 'admin';
$my_deps  = user_departments($me);
$my_dep   = $my_deps[0] ?? null;
$myDepPlaceholders = $my_deps ? implode(',', array_fill(0, count($my_deps), '?')) : "''";
$can_see_riassetti = user_is_reception_or_amministrazione($user) || user_is_housekeeping($user);

// --- Prossimi 5 TASK (stato aperto) ---
// Admin: tutti; Non admin: task assegnati direttamente o al proprio dipartimento.
$taskArgs = [$user['id']];
$taskWhere = 't.deleted_at IS NULL AND t.status = "aperto"';
if (!$is_admin) {
  $myTaskCondition = '((NOT EXISTS (SELECT 1 FROM task_user_assignments tv WHERE tv.task_id = t.id) AND t.dipartimento IN ('.$myDepPlaceholders.')) OR tua_me.user_id IS NOT NULL)';
  $taskWhere .= ' AND (' . $myTaskCondition . ')';
  $taskArgs = array_merge($taskArgs, $my_deps);
}
$qTasks = "SELECT t.id, t.title, t.priority, t.dipartimento, t.due_date,
                  (SELECT GROUP_CONCAT(TRIM(CONCAT(COALESCE(au.cognome, ''), ' ', COALESCE(au.nome, ''))) ORDER BY au.cognome, au.nome SEPARATOR ', ')
                   FROM task_user_assignments tua_names
                   JOIN users au ON au.id = tua_names.user_id
                   WHERE tua_names.task_id = t.id) AS assigned_user_names
           FROM tasks t
           LEFT JOIN task_user_assignments tua_me ON tua_me.task_id = t.id AND tua_me.user_id = ?
           WHERE $taskWhere
           ORDER BY t.due_date ASC, FIELD(t.priority,'urgente','alta','media','bassa') ASC, t.id DESC
           LIMIT 5";
$stt = $pdo->prepare($qTasks);
$stt->execute($taskArgs);
$tasks = $stt->fetchAll();

$tin = [];
$tex = [];

if ($seasonActive) {
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
}

$riassettiToday = [];
if ($seasonActive && $can_see_riassetti) {
  $tz = new DateTimeZone('Europe/Rome');
  $todayRiassetto = (new DateTime('today', $tz))->format('Y-m-d');
  $tomorrowRiassetto = (new DateTime('tomorrow', $tz))->format('Y-m-d');
  $stRi = $pdo->prepare("SELECT id, data_riassetto, room, qty_matrimoniale, qty_singola, qty_set_bagno, pulizia_extra, note, status, completed_at
                          FROM riassetti
                          WHERE data_riassetto IN (?, ?)
                             OR (data_riassetto < ? AND COALESCE(NULLIF(status, ''), CASE WHEN completed_at IS NULL THEN 'da_preparare' ELSE 'concluso' END) <> 'concluso')
                          ORDER BY CASE WHEN data_riassetto < ? AND COALESCE(NULLIF(status, ''), CASE WHEN completed_at IS NULL THEN 'da_preparare' ELSE 'concluso' END) <> 'concluso' THEN 0 ELSE 1 END ASC,
                                   data_riassetto ASC, room ASC, id ASC");
  $stRi->execute([$todayRiassetto, $tomorrowRiassetto, $todayRiassetto, $todayRiassetto]);
  $riassettiToday = $stRi->fetchAll();
}

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

function riassetti_biancheria_short(array $row): string {
  $parts = [];
  if (!empty($row['qty_matrimoniale'])) $parts[] = $row['qty_matrimoniale'] . ' Matrimoniale';
  if (!empty($row['qty_singola'])) $parts[] = $row['qty_singola'] . ' Singola';
  if (!empty($row['qty_set_bagno'])) $parts[] = $row['qty_set_bagno'] . ' Set Bagno';
  return $parts ? implode(', ', $parts) : 'Solo controllo';
}


function riassetti_dashboard_is_overdue(array $row): bool {
  $status = trim((string)($row['status'] ?? ''));
  if ($status === '') $status = !empty($row['completed_at']) ? 'concluso' : 'da_preparare';
  if ($status === 'concluso' || empty($row['data_riassetto'])) return false;

  $tz = new DateTimeZone('Europe/Rome');
  $due = DateTime::createFromFormat('Y-m-d', (string)$row['data_riassetto'], $tz);
  if (!$due) return false;
  $due->setTime(0, 0, 0);
  $today = new DateTime('today', $tz);
  return $due < $today;
}

function riassetti_dashboard_delay_days(array $row): int {
  if (empty($row['data_riassetto'])) return 0;
  $tz = new DateTimeZone('Europe/Rome');
  $due = DateTime::createFromFormat('Y-m-d', (string)$row['data_riassetto'], $tz);
  if (!$due) return 0;
  $due->setTime(0, 0, 0);
  $today = new DateTime('today', $tz);
  return max(0, (int)$due->diff($today)->format('%a'));
}

function riassetti_dashboard_status_label(array $row): string {
  $status = trim((string)($row['status'] ?? ''));
  if ($status === '') $status = !empty($row['completed_at']) ? 'concluso' : 'da_preparare';
  return match ($status) {
    'da_preparare' => 'Da Preparare',
    'da_consegnare' => 'Da Consegnare',
    'concluso' => 'Concluso',
    default => ucfirst(str_replace('_', ' ', $status)),
  };
}

function riassetti_dashboard_status_class(array $row): string {
  $status = trim((string)($row['status'] ?? ''));
  if ($status === '') $status = !empty($row['completed_at']) ? 'concluso' : 'da_preparare';
  return match ($status) {
    'da_preparare' => 'bg-info text-dark',
    'da_consegnare' => 'bg-warning text-dark',
    'concluso' => 'bg-success',
    default => 'bg-secondary',
  };
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
              <?php $taskRecipientLabel = !empty($t['assigned_user_names']) ? $t['assigned_user_names'] : $t['dipartimento']; ?>
              <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">
                <div class="me-2">
                  <div class="fw-semibold"><?= e($t['title']) ?></div>
                  <div class="small text-muted">
                    Scad.: <?= it_date($t['due_date']) ?> · Dest.: <?= e($taskRecipientLabel) ?>
                  </div>
                </div>
                <div class="text-end">
                  <div class="mb-1"><?= badge_priority($t['priority']) ?></div>
                  <form method="post" action="<?= e($base) ?>/task_status.php" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="return_to" value="dashboard">
                    <button class="btn btn-sm btn-outline-success" title="Completa" aria-label="Completa task">
                      <i class="bi bi-check2-circle"></i>
                    </button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($seasonActive): ?>
    <?php if ($can_see_riassetti): ?>
    <!-- BOX RIASSETTI -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><i class="bi bi-house-gear me-1"></i> Riassetti scaduti/oggi/domani</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/riassetti.php" title="Vai alla sezione">Apri</a>
          </div>
          <?php if (empty($riassettiToday)): ?>
            <div class="text-muted small">Nessun riassetto scaduto o previsto per oggi/domani.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($riassettiToday as $ri): ?>
                <?php
                  $riassettoOverdue = riassetti_dashboard_is_overdue($ri);
                  $riassettoDelayDays = $riassettoOverdue ? riassetti_dashboard_delay_days($ri) : 0;
                ?>
                <li class="list-group-item px-2 d-flex justify-content-between align-items-start <?= $riassettoOverdue ? 'border border-2 border-danger rounded-3 bg-danger-subtle' : '' ?>">
                  <div class="me-2">
                    <div class="fw-semibold <?= $riassettoOverdue ? 'text-danger' : '' ?>">
                      <?php if ($riassettoOverdue): ?><i class="bi bi-exclamation-triangle-fill me-1"></i><?php endif; ?>
                      Camera <?= e($ri['room']) ?>
                    </div>
                    <div class="small <?= $riassettoOverdue ? 'text-danger fw-semibold' : 'text-muted' ?>">Data riassetto: <?= it_date($ri['data_riassetto'] ?? '') ?></div>
                    <?php if ($riassettoOverdue): ?>
                      <div class="small fw-bold text-danger text-uppercase">
                        <i class="bi bi-alarm-fill me-1"></i>Scaduto da <?= (int)$riassettoDelayDays ?> <?= $riassettoDelayDays === 1 ? 'giorno' : 'giorni' ?>: concludere il riassetto
                      </div>
                    <?php endif; ?>
                    <div class="small text-muted">
                      <?= e(riassetti_biancheria_short($ri)) ?>
                      <?php if (!empty($ri['pulizia_extra'])): ?>
                        · Pulizia extra
                      <?php endif; ?>
                    </div>
                    <?php if (!empty(trim((string)($ri['note'] ?? '')))): ?>
                      <div class="small">
                        <?= nl2br(e($ri['note'])) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <span class="badge <?= e(riassetti_dashboard_status_class($ri)) ?>"><?= e(riassetti_dashboard_status_label($ri)) ?></span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($my_dep !== "Manutenzione"): ?>
    <!-- BOX TRANSFER INTERNI -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><i class="bi bi-building-check me-1"></i>Prossimi Transfer Interni</h2>
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
            <h2 class="h6 mb-0"><i class="bi bi-bus-front me-1"></i>Prossimi Transfer Esterni</h2>
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
    <?php endif; ?>
  <?php endif; ?>
  
</div>  
<?php if ($seasonActive): ?>
<div class="row g-4">
  <?php
// --- BOX: Giorni liberi prossimi 7 giorni ---
if ($user && (is_admin() || user_has_department($user, 'Amministrazione'))) {
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
                  <td><span class="badge bg-light text-dark"><?= e(departments_label($r['dipartimento'] ?? '')) ?></span></td>
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
if ($user && (is_admin() || user_has_department($user, 'Amministrazione') || user_has_department($user, 'Bar'))) {

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
    WHERE COALESCE(p.is_active, 1) = 1
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
        <a class="btn btn-sm btn-outline-primary" href="<?= e($base) ?>/inventory/products.php">Magazzino</a>
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
if ($user && (is_admin() || user_has_department($user, 'Amministrazione'))) {

  // 0=Dom, 1=Lun, ... 6=Sab
  $tz = new DateTimeZone('Europe/Rome');
  $todayIdx = (int)(new DateTime('now', $tz))->format('w');

  ensure_suppliers_active_column($pdo);
  $sql = "
    SELECT s.id, s.name, s.phone, s.email
    FROM suppliers s
    JOIN supplier_days d
      ON d.supplier_id = s.id
     AND d.kind = 'order'
     AND d.day = :day
    WHERE COALESCE(s.is_active, 1) = 1
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
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card h-100 shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-cloud-sun me-1"></i>Previsioni Meteo</h2>
        </div>
        <div class="ratio" style="--bs-aspect-ratio:50%;">
          <iframe src="https://www.3bmeteo.com/moduli_esterni/localita_7_giorni_compatto/11525246/ffffff/4a4a4a/5e5e5e/ffffff/it" class="border-0 w-100 h-100" allowtransparency="true"></iframe>
        </div>
        <div class="small text-end mt-2">
          <a href="https://www.3bmeteo.com/meteo/capo+vaticano" target="_blank" rel="noopener">Meteo Capo Vaticano</a>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card h-100 shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="h6 mb-0"><i class="bi bi-water me-1"></i>Previsioni Mare</h2>
        </div>
        <div class="ratio" style="--bs-aspect-ratio:96%;">
          <iframe src="https://www.lamma.toscana.it/previ/ita/widget_mare_500.php?area=D" class="border-0 w-100 h-100" scrolling="no"></iframe>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
