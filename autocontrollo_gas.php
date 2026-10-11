<?php
require_once __DIR__ . '/core/autocontrollo_gas.php';
require_once __DIR__ . '/core/security.php';
require_login();
$user = current_user();
if (!$user || !autocontrollo_user_can_perform($user, 'gas')) { http_response_code(403); exit('Accesso negato.'); }
$pdo = db(); ensure_autocontrollo_gas_inspections_tables($pdo);
$env = require __DIR__ . '/config/env.php'; $base = rtrim($env['app']['base_url'] ?? '', '/');
$range = get_summer_season_range($pdo); $schedule = autocontrollo_gas_schedule($range);
$today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token CSRF non valido.'); }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'start') {
            $id = autocontrollo_gas_start($pdo, $range, (string)($_POST['scheduled_date'] ?? ''), (int)$user['id']);
            header('Location: ' . $base . '/autocontrollo_gas.php?inspection=' . $id); exit;
        }
        if ($action !== 'complete') throw new InvalidArgumentException('Azione non valida.');
        $id = (int)($_POST['inspection_id'] ?? 0);
        autocontrollo_gas_complete($pdo, $id, (string)($_POST['liters'] ?? ''), (int)$user['id']);
        try { $_SESSION['gas_report_result'] = autocontrollo_gas_send_report($pdo, $id); }
        catch (Throwable $exception) { error_log('[Gas report] ' . $exception->getMessage()); $_SESSION['gas_report_result'] = ['sent'=>0,'total'=>null]; }
        header('Location: ' . $base . '/autocontrollo_gas.php?completed=' . $id); exit;
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
}
$stmt = $pdo->prepare("SELECT i.*,TRIM(CONCAT_WS(' ',u.nome,u.cognome)) operator_name,u.email operator_email FROM autocontrollo_gas_inspections i LEFT JOIN users u ON u.id=i.operator_id WHERE season_start=? AND season_end=? ORDER BY scheduled_date DESC");
$stmt->execute([$range['start'] ?? '', $range['end'] ?? '']); $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$open = array_values(array_filter($inspections, static fn($r) => $r['status'] === 'in_corso'))[0] ?? null;
$next = autocontrollo_gas_next_date($schedule, array_column(array_filter($inspections, static fn($r) => $r['status'] === 'completata'), 'scheduled_date'));
$report = $_SESSION['gas_report_result'] ?? null; unset($_SESSION['gas_report_result']);
$title = 'Autocontrollo Livello Gas'; include __DIR__ . '/partials/header.php';
?>
<h1 class="h4 mb-4"><i class="bi bi-speedometer2 me-1"></i>Autocontrollo Livello Gas</h1>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($report !== null): ?><div class="alert <?= $report['sent'] > 0 ? 'alert-success' : 'alert-warning' ?>">Rilevazione salvata. <?php if ($report['sent'] > 0): ?>Riepilogo affidato al sistema di posta per <?= (int)$report['sent'] ?> destinatari Amministrazione su <?= (int)$report['total'] ?>.<?php else: ?>Email non inviata: verificare i destinatari Amministrazione e il sistema di posta.<?php endif; ?></div><?php endif; ?>
<p class="text-muted">Controllo ogni 30 giorni, dal quindicesimo giorno prima dell’apertura fino alla chiusura. Soglia minima attuale: <strong><?= autocontrollo_get_gas_alert_level($pdo) ?> litri</strong>.</p>
<div class="card shadow-sm mb-4"><div class="card-body">
<?php if ($open): ?>
<h2 class="h5">Verifica il livello del bombolone</h2>
<p>Controllo previsto il <?= e((new DateTimeImmutable($open['scheduled_date']))->format('d/m/Y')) ?>. Leggi la giacenza del gas e inserisci il valore rilevato in litri.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="inspection_id" value="<?= (int)$open['id'] ?>">
<label class="form-label" for="gasLiters">Giacenza rilevata (litri)</label><input class="form-control mb-3" type="number" id="gasLiters" name="liters" min="0" max="9999999999.99" step="0.01" required value="<?= e($_POST['liters'] ?? '') ?>"><button class="btn btn-primary">Salva e completa la procedura</button></form>
<?php elseif (!$schedule): ?><div class="alert alert-warning mb-0">Configurare date valide di apertura e chiusura nei setting generali.</div>
<?php elseif (!$next): ?><p class="mb-0">Tutti i controlli della stagione sono completati.</p>
<?php else: ?><h2 class="h5">Prossimo controllo: <?= e((new DateTimeImmutable($next))->format('d/m/Y')) ?></h2>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="scheduled_date" value="<?= e($next) ?>"><button class="btn btn-primary" <?= $next > $today ? 'disabled' : '' ?>><?= $next > $today ? 'Non ancora disponibile' : 'Avvia procedura' ?></button></form>
<?php endif; ?>
</div></div>
<div class="card"><div class="card-body"><h2 class="h5">Rilevazioni della stagione</h2><div class="table-responsive"><table class="table"><thead><tr><th>Data prevista</th><th>Rilevazione</th><th>Operatore</th><th>Giacenza (litri)</th><th>Soglia (litri)</th><th>Stato</th></tr></thead><tbody>
<?php if (!$inspections): ?><tr><td colspan="6">Nessuna procedura avviata.</td></tr><?php endif; ?>
<?php foreach ($inspections as $item): $low = $item['liters'] !== null && (float)$item['liters'] <= (int)$item['alert_threshold']; ?>
<tr class="<?= $low ? 'table-danger' : '' ?>"><td><?= e((new DateTimeImmutable($item['scheduled_date']))->format('d/m/Y')) ?></td><td><?= $item['completed_at'] ? e((new DateTimeImmutable($item['completed_at']))->format('d/m/Y H:i')) : '—' ?></td><td><?= e($item['operator_name'] ?: ($item['operator_email'] ?? '—')) ?></td><td class="<?= $low ? 'text-decoration-underline fw-bold' : '' ?>"><?= $item['liters'] !== null ? e(number_format((float)$item['liters'], 2, ',', '.')) : '—' ?><?= $low ? ' — Sotto soglia o pari' : '' ?></td><td><?= $item['alert_threshold'] !== null ? (int)$item['alert_threshold'] : '—' ?></td><td><?= e($item['status']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div></div>
<?php include __DIR__ . '/partials/footer.php'; ?>
