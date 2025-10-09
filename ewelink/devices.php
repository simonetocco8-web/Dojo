<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
require_once __DIR__ . '/../core/ewelink.php';

require_login();
require_admin();

$env = require __DIR__ . '/../config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();
$title = 'Dispositivi eWeLink';

$status = $_GET['status'] ?? '';
$message = $_GET['message'] ?? '';

$tokens = null;
$devices = [];
$error = '';

if (ewelink_is_configured()) {
    $tokens = ewelink_get_tokens((int)$user['id']);
    if ($tokens) {
        try {
            $result = ewelink_fetch_devices((int)$user['id']);
            $devices = $result['devices'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Dispositivi eWeLink</h1>
  <div class="d-flex gap-2">
    <?php if (ewelink_is_configured() && $tokens): ?>
      <form action="<?= e($base) ?>/ewelink/disconnect.php" method="post" onsubmit="return confirm('Scollegare l\'account eWeLink?');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-outline-danger btn-sm">Scollega account</button>
      </form>
    <?php elseif (ewelink_is_configured()): ?>
      <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/ewelink/connect.php">Collega account</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($status === 'connected'): ?>
  <div class="alert alert-success">Account eWeLink collegato con successo.</div>
<?php elseif ($status === 'disconnected'): ?>
  <div class="alert alert-success">Account eWeLink scollegato.</div>
<?php elseif ($status === 'control_ok'): ?>
  <div class="alert alert-success">Comando inviato al dispositivo.</div>
<?php elseif ($status === 'oauth_error'): ?>
  <div class="alert alert-danger">Errore OAuth: <?= e($message) ?></div>
<?php elseif ($status === 'oauth_state'): ?>
  <div class="alert alert-danger">La verifica di sicurezza dello stato OAuth è fallita. Riprova.</div>
<?php elseif ($status === 'oauth_config'): ?>
  <div class="alert alert-warning">Completa la configurazione OAuth in <code>config/env.php</code> prima di collegare l'account.</div>
<?php elseif ($status === 'control_error'): ?>
  <div class="alert alert-danger">Errore durante il controllo del dispositivo: <?= e($message) ?></div>
<?php endif; ?>

<?php if (!ewelink_is_configured()): ?>
  <div class="alert alert-warning">
    <p class="mb-1"><strong>Configurazione richiesta.</strong></p>
    <p class="mb-0">Inserisci <code>client_id</code>, <code>client_secret</code> e <code>redirect_uri</code> nella sezione <code>ewelink</code> di <code>config/env.php</code> per abilitare l'integrazione.</p>
  </div>
<?php elseif (!$tokens): ?>
  <div class="alert alert-info">
    <p class="mb-0">Nessun account eWeLink collegato. Clicca su "Collega account" per autorizzare l'accesso ai dispositivi.</p>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger">Impossibile recuperare i dispositivi: <?= e($error) ?></div>
<?php endif; ?>

<?php if ($tokens && !$error): ?>
  <?php if (empty($devices)): ?>
    <div class="alert alert-secondary">Nessun dispositivo disponibile nell'account eWeLink collegato.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Nome</th>
                <th>ID</th>
                <th>Online</th>
                <th>Parametri</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($devices as $device): ?>
                <?php
                  $onlineValue = $device['online'];
                  $onlineState = null;
                  if (is_bool($onlineValue)) {
                      $onlineState = $onlineValue;
                  } elseif (is_numeric($onlineValue)) {
                      $onlineState = ((int)$onlineValue) === 1;
                  } elseif (is_string($onlineValue)) {
                      $onlineState = in_array(strtolower($onlineValue), ['1', 'true', 'online'], true);
                  }
                  $params = $device['params'] ?? [];
                  $switchState = is_array($params) && isset($params['switch']) ? (string)$params['switch'] : '';
                  $paramsList = [];
                  if (is_array($params)) {
                      foreach ($params as $key => $value) {
                          if (is_array($value) || is_object($value)) {
                              $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                          }
                          $paramsList[] = e($key) . ': ' . e((string)$value);
                      }
                  }
                ?>
                <tr>
                  <td><?= e($device['name'] ?? $device['id']) ?></td>
                  <td><code><?= e($device['id']) ?></code></td>
                  <td>
                    <?php if ($onlineState === null): ?>
                      <span class="badge text-bg-secondary">Sconosciuto</span>
                    <?php elseif ($onlineState): ?>
                      <span class="badge text-bg-success">Online</span>
                    <?php else: ?>
                      <span class="badge text-bg-danger">Offline</span>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?php if ($paramsList): ?>
                      <?= implode('<br>', $paramsList) ?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="d-inline-flex gap-1">
                      <form method="post" action="<?= e($base) ?>/ewelink/device_action.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="device_id" value="<?= e($device['id']) ?>">
                        <input type="hidden" name="command" value="turn_on">
                        <button class="btn btn-sm btn-success<?= $switchState === 'on' ? ' active' : '' ?>"<?= $onlineState === false ? ' disabled' : '' ?>>Accendi</button>
                      </form>
                      <form method="post" action="<?= e($base) ?>/ewelink/device_action.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="device_id" value="<?= e($device['id']) ?>">
                        <input type="hidden" name="command" value="turn_off">
                        <button class="btn btn-sm btn-outline-secondary<?= $switchState === 'off' ? ' active' : '' ?>"<?= $onlineState === false ? ' disabled' : '' ?>>Spegni</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
