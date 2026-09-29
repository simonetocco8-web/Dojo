<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/roles.php';
require_once __DIR__ . '/core/season_end_report.php';
require_login();
$user = current_user();
if (!user_is_admin($user) && !user_is_amministrazione($user)) { http_response_code(403); exit('Accesso negato'); }
$env = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo = db();
[$start, $end] = season_end_report_range($pdo);
$title = 'Report Fine Stagione';
include __DIR__ . '/partials/header.php';
?>
<div class="card shadow-sm">
  <div class="card-body p-4">
    <h1 class="h4"><i class="bi bi-file-earmark-pdf me-2"></i>Report Fine Stagione</h1>
    <p class="text-muted">Genera un PDF riepilogativo dal <?= e($start->format('d/m/Y')) ?> al <?= e($end->format('d/m/Y')) ?> con Report TramontoDay, Statistiche Trasporti e Statistiche Riassetti.</p>
    <a class="btn btn-primary" href="<?= e($base) ?>/reports/season_end_pdf.php"><i class="bi bi-download me-1"></i>Scarica PDF</a>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
