<?php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/ewelink_mcp.php';

require_login();
require_admin();

$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$title = 'Test MCP Boiler Cottage';
$testResult = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !csrf_check((string)$_POST['csrf'])) {
        http_response_code(400);
        $error = 'Token CSRF non valido.';
    } else {
        try {
            $testResult = ewelink_mcp_test_device_raw('Boiler Cottage');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-1">Test MCP Boiler Cottage</h1>
    <p class="text-muted mb-0">Interrogazione in sola lettura; URL e token MCP non vengono mostrati.</p>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e($base) ?>/ewelink/devices.php">Torna ai dispositivi</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="mb-4">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <button class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Interroga Boiler Cottage</button>
</form>

<?php if ($testResult !== null): ?>
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Record corrispondenti a Boiler Cottage</div>
    <div class="card-body">
      <pre class="bg-light border rounded p-3 mb-0 text-wrap text-break"><?= e(json_encode($testResult['matched_devices'], $jsonFlags)) ?></pre>
    </div>
  </div>

  <h2 class="h6">Scambio MCP completo</h2>
  <?php foreach ($testResult['steps'] as $index => $step): ?>
    <details class="card shadow-sm mb-3"<?= $step['method'] === 'tools/call' ? ' open' : '' ?>>
      <summary class="card-header fw-semibold" role="button"><?= e(($index + 1) . '. ' . $step['method']) ?></summary>
      <div class="card-body">
        <h3 class="h6">Richiesta</h3>
        <pre class="bg-light border rounded p-3 text-wrap text-break"><?= e(json_encode($step['request'], $jsonFlags)) ?></pre>
        <h3 class="h6">Risposta</h3>
        <pre class="bg-light border rounded p-3 mb-0 text-wrap text-break"><?= e(json_encode($step['response'], $jsonFlags)) ?></pre>
      </div>
    </details>
  <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
