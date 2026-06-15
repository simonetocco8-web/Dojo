<?php
require_once __DIR__ . '/_role_guard.php';
require_once __DIR__ . '/../core/sms.php';
require_once __DIR__ . '/../core/mailer.php';
if (!$user) { header('Location: ../login.php?msg=auth'); exit; }
if (!is_bar_or_amministrazione()) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); echo 'Solo Bar o Amministrazione.'; exit; }

ensure_products_active_column($pdo);

$message = '';
$messageType = 'info';
$summary = [];
$warnings = [];
$notificationNotes = [];
$warehouses = ['Tizzo','Tramonto'];
$wh = $_POST['warehouse'] ?? '';

function scarico_department_phones(PDO $pdo, string $department): array {
  $needle = str_replace(' ', '', $department);
  $stmt = $pdo->prepare("\n    SELECT telefono\n    FROM users\n    WHERE deleted_at IS NULL\n      AND is_active = 1\n      AND telefono <> ''\n      AND FIND_IN_SET(?, REPLACE(dipartimento, ' ', '')) > 0\n  ");
  $stmt->execute([$needle]);
  return array_values(array_unique(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
}

function scarico_department_emails(PDO $pdo, string $department): array {
  $needle = str_replace(' ', '', $department);
  $stmt = $pdo->prepare("\n    SELECT email\n    FROM users\n    WHERE deleted_at IS NULL\n      AND is_active = 1\n      AND email <> ''\n      AND FIND_IN_SET(?, REPLACE(dipartimento, ' ', '')) > 0\n  ");
  $stmt->execute([$needle]);
  return array_values(array_unique(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
}

function scarico_sms_body(string $warehouse): string {
  return 'Nuovo scarico magazzino ' . $warehouse . '. Dettagli operazione inviati via email.';
}

function scarico_email_body(string $warehouse, array $rows, array $warnings, array $criticalRows, array $user): string {
  $sender = trim(($user['cognome'] ?? '') . ' ' . ($user['nome'] ?? ''));
  if ($sender === '') $sender = $user['email'] ?? 'Utente';
  $when = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
  ob_start(); ?>
  <h2>Ordine scarico magazzino <?= htmlspecialchars($warehouse, ENT_QUOTES, 'UTF-8') ?></h2>
  <p><strong>Data/Ora:</strong> <?= htmlspecialchars($when, ENT_QUOTES, 'UTF-8') ?><br>
     <strong>Operatore:</strong> <?= htmlspecialchars($sender, ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($rows): ?>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">
      <thead><tr style="background:#f2f2f2;"><th align="left">Prodotto</th><th>Q.ta</th><th>Unità di Misura</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $isZero = (float)$row['now'] <= 0;
        $isLow = !$isZero && (float)$row['now'] < (float)$row['min_qty'];
        $style = ($isZero || $isLow) ? 'background:#fff3cd;color:#842029;font-weight:bold;' : '';
      ?>
        <tr style="<?= $style ?>">
          <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars((string)((float)$row['qty'] + 0), ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars($row['unit'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($warnings): ?>
    <h3>Prodotti non disponibili nel magazzino selezionato</h3>
    <ul>
      <?php foreach ($warnings as $warning): ?>
        <li><strong><?= htmlspecialchars($warning['title'], ENT_QUOTES, 'UTF-8') ?></strong>: richiesti <?= htmlspecialchars((string)((float)$warning['requested'] + 0), ENT_QUOTES, 'UTF-8') ?>, disponibili in <?= htmlspecialchars($warning['suggest_wh'], ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)((float)$warning['available'] + 0), ENT_QUOTES, 'UTF-8') ?>.</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($criticalRows): ?>
    <h3 style="color:#842029;">Attenzione: prodotti a zero o sottoscorta</h3>
    <ul>
      <?php foreach ($criticalRows as $row): ?>
        <li><strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>: giacenza residua <?= htmlspecialchars((string)((float)$row['now'] + 0), ENT_QUOTES, 'UTF-8') ?> / minima <?= htmlspecialchars((string)((float)$row['min_qty'] + 0), ENT_QUOTES, 'UTF-8') ?>.</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php return trim(ob_get_clean());
}

function scarico_admin_email_body(string $warehouse, array $rows, array $warnings, array $criticalRows, array $user): string {
  $sender = trim(($user['cognome'] ?? '') . ' ' . ($user['nome'] ?? ''));
  if ($sender === '') $sender = $user['email'] ?? 'Utente';
  $when = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
  ob_start(); ?>
  <h2>Riepilogo scarico magazzino <?= htmlspecialchars($warehouse, ENT_QUOTES, 'UTF-8') ?></h2>
  <p><strong>Data/Ora:</strong> <?= htmlspecialchars($when, ENT_QUOTES, 'UTF-8') ?><br>
     <strong>Operatore:</strong> <?= htmlspecialchars($sender, ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($rows): ?>
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">
      <thead><tr style="background:#f2f2f2;"><th align="left">Prodotto</th><th>Q.tà</th><th>Prima</th><th>Dopo</th><th>Min</th><th>Stato</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $isZero = (float)$row['now'] <= 0;
        $isLow = !$isZero && (float)$row['now'] < (float)$row['min_qty'];
        $status = $isZero ? 'GIACENZA ZERO' : ($isLow ? 'SOTTOSCORTA' : 'OK');
        $style = ($isZero || $isLow) ? 'background:#fff3cd;color:#842029;font-weight:bold;' : '';
      ?>
        <tr style="<?= $style ?>">
          <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars((string)((float)$row['qty'] + 0), ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars((string)((float)$row['prev'] + 0), ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars((string)((float)$row['now'] + 0), ENT_QUOTES, 'UTF-8') ?></td>
          <td align="center"><?= htmlspecialchars((string)((float)$row['min_qty'] + 0), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($warnings): ?>
    <h3>Prodotti non disponibili nel magazzino selezionato</h3>
    <ul>
      <?php foreach ($warnings as $warning): ?>
        <li><strong><?= htmlspecialchars($warning['title'], ENT_QUOTES, 'UTF-8') ?></strong>: richiesti <?= htmlspecialchars((string)((float)$warning['requested'] + 0), ENT_QUOTES, 'UTF-8') ?>, disponibili in <?= htmlspecialchars($warning['suggest_wh'], ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)((float)$warning['available'] + 0), ENT_QUOTES, 'UTF-8') ?>.</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($criticalRows): ?>
    <h3 style="color:#842029;">Attenzione: prodotti a zero o sottoscorta</h3>
    <ul>
      <?php foreach ($criticalRows as $row): ?>
        <li><strong><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></strong>: giacenza residua <?= htmlspecialchars((string)((float)$row['now'] + 0), ENT_QUOTES, 'UTF-8') ?> / minima <?= htmlspecialchars((string)((float)$row['min_qty'] + 0), ENT_QUOTES, 'UTF-8') ?>.</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php return trim(ob_get_clean());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
    $messageType = 'danger';
  } else {
    if (!in_array($wh, $warehouses, true)) $wh = 'Tizzo';
    $otherWh = ($wh === 'Tizzo') ? 'Tramonto' : 'Tizzo';

    $valid = [];
    foreach (($_POST['items'] ?? []) as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (float)($it['qty'] ?? 0);
      if ($pid > 0 && $qty > 0) $valid[] = ['pid' => $pid, 'qty' => $qty];
    }

    if (!$valid) {
      $message = 'Nessun prodotto selezionato: cerca un prodotto, indica la quantità e aggiungilo alla lista.';
      $messageType = 'warning';
    } else {
      $stGetQty = $pdo->prepare('SELECT qty FROM stock_levels WHERE product_id = ? AND warehouse = ?');
      $stSetQty = $pdo->prepare("\n        INSERT INTO stock_levels (product_id, warehouse, qty)\n        VALUES (?, ?, ?)\n        ON DUPLICATE KEY UPDATE qty = VALUES(qty)\n      ");
      $stLog = $pdo->prepare("\n        INSERT INTO stock_movements (product_id, warehouse, type, qty_delta, created_by)\n        VALUES (?, ?, ?, ?, ?)\n      ");
      $stProduct = $pdo->prepare('SELECT title, unit, min_qty FROM products WHERE id = ? AND COALESCE(is_active, 1) = 1');

      $pdo->beginTransaction();
      try {
        foreach ($valid as $v) {
          $pid = $v['pid'];
          $qty = $v['qty'];

          $stProduct->execute([$pid]);
          $info = $stProduct->fetch(PDO::FETCH_ASSOC);
          if (!$info) continue;

          $stGetQty->execute([$pid, $wh]);
          $curWh = (float)($stGetQty->fetchColumn() ?: 0);
          $stGetQty->execute([$pid, $otherWh]);
          $curOther = (float)($stGetQty->fetchColumn() ?: 0);

          if ($curWh <= 0 && $curOther > 0) {
            $warnings[] = [
              'product_id' => $pid,
              'title' => $info['title'],
              'suggest_wh' => $otherWh,
              'available' => $curOther,
              'requested' => $qty,
              'unit' => $info['unit'] ?? '',
              'now' => 0,
              'min_qty' => (float)$info['min_qty'],
            ];
            continue;
          }

          $new = max($curWh - $qty, 0);
          $stSetQty->execute([$pid, $wh, $new]);
          $stLog->execute([$pid, $wh, 'scarico', -abs($qty), $user['id']]);

          $summary[] = [
            'product_id' => $pid,
            'title' => $info['title'],
            'qty' => $qty,
            'unit' => $info['unit'] ?? '',
            'prev' => $curWh,
            'now' => $new,
            'min_qty' => (float)$info['min_qty'],
          ];
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        error_log('SCARICO-ERR: ' . $e->getMessage());
        $message = 'Errore durante lo scarico: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $messageType = 'danger';
      }

      if (!$message) {
        $_SESSION['last_scarico_pdf'] = [
          'when' => (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i'),
          'warehouse' => $wh,
          'rows' => $summary,
          'warnings' => $warnings,
        ];

        $criticalRows = array_values(array_filter($summary, function($row) {
          return (float)$row['now'] <= 0 || (float)$row['now'] < (float)$row['min_qty'];
        }));
        foreach ($warnings as $warning) {
          $criticalRows[] = $warning;
        }

        $warehouseDepartment = ($wh === 'Tizzo') ? 'Magazziniere Tizzo' : 'Magazziniere Tramonto';
        $departmentEmails = scarico_department_emails($pdo, $warehouseDepartment);
        if ($departmentEmails && ($summary || $warnings)) {
          $subject = 'Dettagli scarico magazzino ' . $wh . ' - ' . (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
          $body = scarico_email_body($wh, $summary, $warnings, $criticalRows, $user);
          foreach ($departmentEmails as $email) {
            send_mail($email, $subject, $body);
          }
          $notificationNotes[] = 'Email dettagli inviata a ' . $warehouseDepartment . '.';
        } else {
          $notificationNotes[] = 'Nessuna email trovata per ' . $warehouseDepartment . '.';
        }

        $adminEmails = scarico_department_emails($pdo, 'Amministrazione');
        if ($adminEmails && ($summary || $warnings)) {
          $subject = 'Riepilogo scarico magazzino ' . $wh . ' - ' . (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
          $body = scarico_admin_email_body($wh, $summary, $warnings, $criticalRows, $user);
          foreach ($adminEmails as $email) {
            send_mail($email, $subject, $body);
          }
          $notificationNotes[] = 'Email riepilogo inviata ad Amministrazione.';
        } else {
          $notificationNotes[] = 'Nessuna email amministrativa trovata per il riepilogo.';
        }

        $smsRecipients = scarico_department_phones($pdo, $warehouseDepartment);
        if ($smsRecipients && ($summary || $warnings)) {
          try {
            sms_send_message($env, $smsRecipients, scarico_sms_body($wh));
            $notificationNotes[] = 'SMS avviso inviato a ' . $warehouseDepartment . '.';
          } catch (Exception $e) {
            error_log('SCARICO-SMS-ERR: ' . $e->getMessage());
            $notificationNotes[] = 'SMS non inviato a ' . $warehouseDepartment . ': ' . $e->getMessage();
          }
        } else {
          $notificationNotes[] = 'Nessun numero SMS trovato per ' . $warehouseDepartment . '.';
        }

        $message = $summary ? 'Ordine di scarico registrato.' : 'Nessun prodotto scaricato.';
        if ($warnings) $message .= ' Alcuni prodotti vanno reperiti dall’altro magazzino.';
        $messageType = $summary ? 'success' : 'warning';
      }
    }
  }
}

$title = 'Scarico Magazzino';
$scaricoJsVersion = @filemtime(__DIR__ . '/../assets/scarico.js') ?: time();
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
  <div>
    <h1 class="h4 mb-1">Scarico</h1>
    <div class="text-muted small">Procedura guidata ottimizzata per smartphone: scegli il magazzino, cerca i prodotti e invia l’ordine.</div>
  </div>
  <div class="d-flex flex-wrap gap-2 no-print">
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

<?php if ($message): ?>
  <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($notificationNotes): ?>
  <div class="alert alert-light border small">
    <div class="fw-semibold mb-1">Notifiche</div>
    <ul class="mb-0">
      <?php foreach ($notificationNotes as $note): ?><li><?= e($note) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($warnings): ?>
  <div class="alert alert-warning">
    <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Prodotti da reperire</div>
    <ul class="mb-0">
      <?php foreach ($warnings as $w): ?>
        <li><strong><?= e($w['title']) ?></strong> — richiesti <?= e((float)$w['requested']) ?>. Non disponibile in <em><?= e($wh) ?></em>, disponibile in <em><?= e($w['suggest_wh']) ?></em>: <?= e((float)$w['available']) ?>.</li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm no-print" id="scaricoApp">
  <div class="card-body p-3 p-lg-4">
    <form method="post" id="scaricoForm" autocomplete="off" data-wait-feedback="Registrazione scarico e invio notifiche in corso...">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="warehouse" id="scaricoWarehouse" value="<?= e(in_array($wh, $warehouses, true) ? $wh : '') ?>">
      <div id="scaricoHiddenItems"></div>

      <section class="scarico-step" id="warehouseStep">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="badge rounded-pill text-bg-primary">1</span>
          <div>
            <h2 class="h5 mb-0">Da quale magazzino prelevi?</h2>
            <div class="text-muted small">Seleziona il magazzino di partenza dell’ordine.</div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <button type="button" class="btn btn-outline-primary warehouse-choice w-100 py-4" data-warehouse="Tizzo">
              <i class="bi bi-building fs-1 d-block mb-2"></i>
              <span class="fs-5 fw-semibold">Tizzo</span>
            </button>
          </div>
          <div class="col-12 col-md-6">
            <button type="button" class="btn btn-outline-success warehouse-choice w-100 py-4" data-warehouse="Tramonto">
              <i class="bi bi-shop-window fs-1 d-block mb-2"></i>
              <span class="fs-5 fw-semibold">Tramonto</span>
            </button>
          </div>
        </div>
      </section>

      <section class="scarico-step d-none" id="productStep">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill text-bg-primary">2</span>
            <div>
              <h2 class="h5 mb-0">Aggiungi prodotti</h2>
              <div class="text-muted small">Magazzino selezionato: <strong id="selectedWarehouseLabel"></strong></div>
            </div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary align-self-start" id="changeWarehouseBtn"><i class="bi bi-arrow-left me-1"></i>Cambia magazzino</button>
        </div>

        <label class="form-label fw-semibold" for="scaricoSearch">Cerca prodotto</label>
        <div class="position-relative mb-2">
          <input id="scaricoSearch" type="search" class="form-control form-control-lg" placeholder="Scrivi titolo o EAN del prodotto">
          <div class="list-group position-absolute w-100 z-3 shadow-sm" id="scaricoResults" style="max-height:260px; overflow:auto;"></div>
        </div>
        <div class="form-text mb-3">Tocca un prodotto dall’elenco: si aprirà il popup per inserire la quantità.</div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h3 class="h6 mb-0">Prodotti da prelevare</h3>
          <span class="badge text-bg-light" id="itemsCount">0 prodotti</span>
        </div>
        <div id="scaricoCartEmpty" class="border rounded-3 p-4 text-center text-muted bg-light">Nessun prodotto aggiunto.</div>
        <div class="list-group mb-3" id="scaricoCart"></div>

        <button class="btn btn-success btn-lg w-100" id="submitScaricoBtn" disabled>
          <i class="bi bi-send-check me-1"></i>Invia Ordine
        </button>
      </section>
    </form>
  </div>
</div>

<?php if ($summary): ?>
<div class="card shadow-sm mt-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 mb-0">Riepilogo Scarico</h2>
      <a class="btn btn-sm btn-outline-secondary no-print" href="scarico_bolla.php" target="_blank">Scarica bolla</a>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Prodotto</th><th>Q.tà</th><th>Prima</th><th>Dopo</th><th>Min</th><th>Stato</th></tr></thead>
        <tbody>
          <?php foreach($summary as $s):
            $isZero = (float)$s['now'] <= 0;
            $isLow = !$isZero && (float)$s['now'] < (float)$s['min_qty'];
          ?>
          <tr class="<?= ($isZero || $isLow) ? 'table-warning' : '' ?>">
            <td><?= e($s['title']) ?></td>
            <td><?= e((float)$s['qty']) ?></td>
            <td><?= e((float)$s['prev']) ?></td>
            <td><?= e((float)$s['now']) ?></td>
            <td><?= e((float)$s['min_qty']) ?></td>
            <td><?php if ($isZero): ?><span class="badge text-bg-danger">Zero</span><?php elseif ($isLow): ?><span class="badge text-bg-warning">Sottoscorta</span><?php else: ?><span class="badge text-bg-success">OK</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="qtyModal" tabindex="-1" aria-labelledby="qtyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qtyModalLabel">Quantità da prelevare</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="fw-semibold" id="qtyProductTitle"></div>
        <div class="text-muted small mb-3" id="qtyProductStock"></div>
        <label class="form-label" for="qtyInput">Quantità</label>
        <input type="number" class="form-control form-control-lg" id="qtyInput" step="0.001" min="0.001" inputmode="decimal">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="button" class="btn btn-success" id="confirmQtyBtn">Aggiungi alla lista</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= e($base) ?>/assets/scarico.js?v=<?= (int)$scaricoJsVersion ?>" defer></script>
<style>
@media print { .no-print { display: none !important; } }
.warehouse-choice { min-height: 150px; border-width: 2px; }
.warehouse-choice.active { box-shadow: 0 0 0 .25rem rgba(13,110,253,.15); }
#scaricoResults:empty { display:none; }
@media (max-width: 575.98px) {
  .warehouse-choice { min-height: 130px; }
  #scaricoCart .list-group-item { padding: 1rem; }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
