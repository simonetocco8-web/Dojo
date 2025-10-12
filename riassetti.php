<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/roles.php';
start_session();

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();
$user = current_user();
if (!$user) { header('Location: ' . $base . '/index.php?msg=auth'); exit; }

$canManage = user_is_reception_or_amministrazione($user);
$canView   = $canManage || user_is_housekeeping($user);
if (!$canView) {
  http_response_code(403);
  echo '<h1>403</h1><p>Accesso non autorizzato.</p>';
  exit;
}

$dateFilter = trim($_GET['date'] ?? '');
if ($dateFilter !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $dateFilter);
  if (!$dt) {
    $dateFilter = '';
  } else {
    $dateFilter = $dt->format('Y-m-d');
  }
}

$where = [];
$params = [];
if ($dateFilter !== '') {
  $where[] = 'r.data_riassetto = ?';
  $params[] = $dateFilter;
}

$sql = 'SELECT r.*, uc.email AS created_by_email, ucomp.email AS completed_by_email
        FROM riassetti r
        LEFT JOIN users uc ON uc.id = r.created_by
        LEFT JOIN users ucomp ON ucomp.id = r.completed_by';
if ($where) {
  $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY r.data_riassetto ASC, r.room ASC, r.id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$riassetti = $stmt->fetchAll();

$msgKey = $_GET['msg'] ?? '';
$alert = '';
if ($msgKey === 'saved') {
  $alert = 'Riassetto salvato con successo.';
} elseif ($msgKey === 'completed') {
  $alert = 'Riassetto aggiornato.';
}
$warnKey = $_GET['warn'] ?? '';
$warnAlert = $warnKey === 'calendar' ? 'Attenzione: evento non sincronizzato su Google Calendar.' : '';

function format_biancheria(array $row): string {
  $parts = [];
  if (!empty($row['qty_matrimoniale'])) $parts[] = $row['qty_matrimoniale'] . ' Matrimoniale';
  if (!empty($row['qty_singola'])) $parts[] = $row['qty_singola'] . ' Singola';
  if (!empty($row['qty_set_bagno'])) $parts[] = $row['qty_set_bagno'] . ' Set Bagno';
  return $parts ? implode(', ', $parts) : '—';
}

function format_data_it(?string $date): string {
  if (!$date) return '';
  $dt = DateTime::createFromFormat('Y-m-d', $date);
  return $dt ? $dt->format('d/m/Y') : $date;
}

$title = 'Riassetti';
include __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Riassetti</h1>
  <div class="d-flex gap-2">
    <?php if ($canManage): ?>
      <a class="btn btn-sm btn-primary" href="<?= e($base) ?>/riassetti_edit.php">+ Nuovo riassetto</a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center">
      <div class="col">
        <form class="row g-2 align-items-center" method="get">
          <div class="col-auto">
            <label for="date" class="col-form-label col-form-label-sm">Data</label>
          </div>
          <div class="col-auto">
            <input type="date" id="date" name="date" value="<?= e($dateFilter) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-primary">Filtra</button>
          </div>
          <?php if ($dateFilter !== ''): ?>
          <div class="col-auto">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/riassetti.php">Pulisci</a>
          </div>
          <?php endif; ?>
        </form>
      </div>
      <div class="col-auto">
        <form class="d-flex gap-2" method="get" action="<?= e($base) ?>/riassetti_pdf.php" target="_blank">
          <input type="date" name="date" value="<?= e($dateFilter) ?>" class="form-control form-control-sm" required>
          <button type="submit" class="btn btn-sm btn-outline-success">Genera PDF</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($alert): ?>
<div class="alert alert-success"><?= e($alert) ?></div>
<?php endif; ?>
<?php if ($warnAlert): ?>
<div class="alert alert-warning"><?= e($warnAlert) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <?php if (empty($riassetti)): ?>
          <p class="text-muted mb-0">Nessun riassetto trovato.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Data</th>
                <th>Camera</th>
                <th>Biancheria</th>
                <th>Pulizia extra</th>
                <th>Note</th>
                <th>Stato</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($riassetti as $r): ?>
              <tr class="<?= $r['completed_at'] ? 'table-success' : '' ?>">
                <td><?= e(format_data_it($r['data_riassetto'])) ?></td>
                <td class="fw-semibold"><?= e($r['room']) ?></td>
                <td><?= e(format_biancheria($r)) ?></td>
                <td>
                  <?php if (!empty($r['pulizia_extra'])): ?>
                    <span class="badge bg-warning text-dark">Sì</span>
                  <?php else: ?>
                    <span class="badge bg-light text-dark border">No</span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= nl2br(e($r['note'] ?? '')) ?></td>
                <td>
                  <?php if (!empty($r['completed_at'])): ?>
                    <span class="badge bg-success">Completato</span>
                  <?php else: ?>
                    <span class="badge bg-info text-dark">Da fare</span>
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <?php if ($canManage): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/riassetti_edit.php?id=<?= (int)$r['id'] ?>" title="Modifica">
                      <i class="bi bi-pencil"></i>
                    </a>
                  <?php endif; ?>
                  <?php if ($canView): ?>
                    <form method="post" action="<?= e($base) ?>/riassetti_status.php" class="d-inline">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI'] ?? ($base . '/riassetti.php')) ?>">
                      <?php if (!empty($r['completed_at'])): ?>
                        <input type="hidden" name="action" value="reopen">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Segna da fare">
                          <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                      <?php else: ?>
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Segna completato">
                          <i class="bi bi-check2-circle"></i>
                        </button>
                      <?php endif; ?>
                    </form>
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
  <div class="col-12 col-lg-6">
    <div class="ratio ratio-4x3">
      <iframe src="https://calendar.google.com/calendar/embed?src=13cbf1c11d4c3501563e17c909423fabeb42b3e74a7869f0dbaf6cfb6d12779b%40group.calendar.google.com&amp;ctz=Europe%2FRome" style="border:0" width="800" height="600" frameborder="0" scrolling="no" allowfullscreen></iframe>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
