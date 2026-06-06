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

if (!$user || !user_is_amministrazione($user)) {
  http_response_code(403); exit('Permesso negato.');
}

ensure_overtime_entries_table($pdo);

$month = $_GET['month'] ?? (new DateTimeImmutable('first day of this month'))->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
}
$selectedUserId = (int)($_GET['user_id'] ?? 0);
$monthlyPayRaw = trim(str_replace(',', '.', (string)($_GET['monthly_pay'] ?? '')));
$monthlyPay = $monthlyPayRaw !== '' ? filter_var($monthlyPayRaw, FILTER_VALIDATE_FLOAT) : null;
if ($monthlyPay === false || $monthlyPay < 0) {
  $monthlyPay = null;
}

$start = $month . '-01';
$end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
$hourlyRate = $monthlyPay !== null ? ((float)$monthlyPay / 30 / 8) : null;

$users = $pdo->query("SELECT id, nome, cognome, email, dipartimento FROM users WHERE deleted_at IS NULL AND is_active = 1 ORDER BY cognome ASC, nome ASC, email ASC")->fetchAll(PDO::FETCH_ASSOC);

$where = [
  'o.deleted_at IS NULL',
  'o.work_date BETWEEN ? AND ?',
];
$args = [$start, $end];
if ($selectedUserId > 0) {
  $where[] = 'o.user_id = ?';
  $args[] = $selectedUserId;
}

$stmt = $pdo->prepare("\n  SELECT u.id, u.nome, u.cognome, u.email, u.dipartimento, COALESCE(SUM(o.hours), 0) AS total_hours\n  FROM overtime_entries o\n  JOIN users u ON u.id = o.user_id\n  WHERE " . implode(' AND ', $where) . "\n  GROUP BY u.id, u.nome, u.cognome, u.email, u.dipartimento\n  ORDER BY u.cognome ASC, u.nome ASC, u.email ASC\n");
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalHours = 0.0;
foreach ($rows as $row) {
  $totalHours += (float)$row['total_hours'];
}
$totalCost = $hourlyRate !== null ? $hourlyRate * $totalHours : null;

$title = 'Calcolo Mensile Straordinari';
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Calcolo Mensile Straordinari</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>/overtime.php">Torna a Straordinari</a>
</div>

<form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Mese</label>
        <input type="month" class="form-control" name="month" value="<?= e($month) ?>" required>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Utente</label>
        <select name="user_id" class="form-select">
          <option value="0">Tutti gli utenti</option>
          <?php foreach ($users as $u): ?>
            <?php $label = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')); $label = $label !== '' ? $label : $u['email']; ?>
            <option value="<?= (int)$u['id'] ?>" <?= $selectedUserId === (int)$u['id'] ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Paga mensile</label>
        <div class="input-group">
          <span class="input-group-text">€</span>
          <input type="number" class="form-control" name="monthly_pay" min="0" step="0.01" value="<?= e($monthlyPayRaw) ?>" placeholder="Es. 1800.00">
        </div>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Calcola</button>
      </div>
    </div>
    <p class="text-muted small mb-0 mt-3">Formula: paga mensile ÷ 30 giorni ÷ 8 ore × ore di straordinario.</p>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Ore extra totali</div>
        <div class="fs-4 fw-semibold"><?= e(number_format($totalHours, 2, ',', '.')) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Costo orario calcolato</div>
        <div class="fs-4 fw-semibold"><?= $hourlyRate !== null ? '€ ' . e(number_format($hourlyRate, 2, ',', '.')) : '—' ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Costo aggiuntivo totale</div>
        <div class="fs-4 fw-semibold"><?= $totalCost !== null ? '€ ' . e(number_format($totalCost, 2, ',', '.')) : '—' ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <h2 class="h6 mb-3">Dettaglio per utente</h2>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Utente</th>
            <th>Dipartimento</th>
            <th class="text-end">Ore extra</th>
            <th class="text-end">Costo aggiuntivo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $rowHours = (float)$row['total_hours']; $rowCost = $hourlyRate !== null ? $hourlyRate * $rowHours : null; ?>
            <tr>
              <td><?= e(trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? '')) ?: ($row['email'] ?? '')) ?></td>
              <td><?= e(departments_label($row['dipartimento'] ?? '')) ?></td>
              <td class="text-end"><?= e(number_format($rowHours, 2, ',', '.')) ?></td>
              <td class="text-end"><?= $rowCost !== null ? '€ ' . e(number_format($rowCost, 2, ',', '.')) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">Nessuno straordinario registrato nel mese selezionato.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
