<?php
// user_edit.php — modifica utente con campi estesi + protezioni
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

require_admin();
start_session(); // assicura la sessione attiva prima di csrf_token()

$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$pdo  = db();

// Leggi ID utente da GET (o POST fallback)
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: ' . $base . '/users.php');
  exit;
}

// Non permettere modifica di utenti nel cestino
$stmt = $pdo->prepare('SELECT id, email, role, is_active, nome, cognome, telefono, dipartimento, deleted_at
                       FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$editUser = $stmt->fetch();

if (!$editUser) {
  header('Location: ' . $base . '/users.php');
  exit;
}
if (!empty($editUser['deleted_at'])) {
  header('Location: ' . $base . '/users.php?trash=1');
  exit;
}

$message = '';
$departmentSchemaReady = true;
try {
  ensure_users_department_column_supports_multiple($pdo);
} catch (PDOException $e) {
  $departmentSchemaReady = false;
  $message = 'Errore aggiornamento struttura dipartimenti utente: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
$allowedDeps = available_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$departmentSchemaReady) {
    // Messaggio già valorizzato dal controllo schema sopra.
  } elseif (!csrf_check($_POST['csrf'] ?? '')) {
    $message = 'Token CSRF non valido.';
  } else {
    $email        = trim($_POST['email'] ?? '');
    $role         = $_POST['role'] ?? 'editor';
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $nome         = trim($_POST['nome'] ?? '');
    $cognome      = trim($_POST['cognome'] ?? '');
    $telefono     = trim($_POST['telefono'] ?? '');
    $dipartimenti = $_POST['dipartimento'] ?? [];
    if (!is_array($dipartimenti)) { $dipartimenti = [$dipartimenti]; }
    $dipartimenti = array_values(array_intersect($allowedDeps, $dipartimenti));
    if (!$dipartimenti) { $dipartimenti = ['Amministrazione']; }
    $dipartimento = implode(',', $dipartimenti);
    $new_password = $_POST['password'] ?? '';

    if ($email && $nome && $cognome) {
      try {
        $pdo = db();
        // 1) email già usata da ALTRI?
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? AND (deleted_at IS NULL)');
        $chk->execute([$email, $id]);
        if ($chk->fetch()) {
          $message = 'Questa email è già in uso da un altro utente.';
        } else {
          // 2) aggiorna
          if ($new_password !== '') {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $sql  = 'UPDATE users
                     SET email=?, role=?, is_active=?, nome=?, cognome=?, telefono=?, dipartimento=?, password_hash=?
                     WHERE id=? AND deleted_at IS NULL';
            $args = [$email, $role, $is_active, $nome, $cognome, $telefono, $dipartimento, $hash, $id];
          } else {
            $sql  = 'UPDATE users
                     SET email=?, role=?, is_active=?, nome=?, cognome=?, telefono=?, dipartimento=?
                     WHERE id=? AND deleted_at IS NULL';
            $args = [$email, $role, $is_active, $nome, $cognome, $telefono, $dipartimento, $id];
          }
          $st = $pdo->prepare($sql);
          $st->execute($args);

          if ($st->rowCount() === 0) {
            // Nessuna riga toccata: o stessi dati, o utente nel cestino
            // Rileggiamo lo stato deleted_at per dare un messaggio sensato
            $r = $pdo->prepare('SELECT deleted_at FROM users WHERE id=?');
            $r->execute([$id]);
            $row = $r->fetch();
            if (!empty($row['deleted_at'])) {
              $message = 'Impossibile modificare: l’utente è nel cestino. Ripristinalo dal Cestino e riprova.';
            } else {
              $message = 'Nessuna modifica da applicare.';
            }
          } else {
            // Ricarica i dati aggiornati
            $stmt = $pdo->prepare('SELECT id, email, role, is_active, nome, cognome, telefono, dipartimento, deleted_at
                                   FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $editUser = $stmt->fetch();
            $message = 'Utente aggiornato con successo.';
          }
        }
      } catch (PDOException $e) {
        // Messaggi più utili sui casi noti
        if ($e->getCode() === '23000') {
          $message = 'Vincolo violato (probabilmente email duplicata).';
        } else {
          $message = 'Si è verificato un errore durante l’aggiornamento.';
          // In dev, per capire subito:
          // $message .= ' Dettaglio: ' . $e->getMessage();
        }
      }
    } else {
      $message = 'Compila i campi obbligatori (nome, cognome, email).';
    }
  }
}


$title = 'Modifica utente';
include __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h5 mb-3">Modifica utente #<?= (int)$editUser['id'] ?></h1>

        <?php if($message): ?>
          <div class="alert alert-info"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome</label><?= e($editUser['nome']) ?>
              <input type="text" name="nome" class="form-control" value="<?= e($editUser['nome']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Cognome</label>
              <input type="text" name="cognome" class="form-control" value="<?= e($editUser['cognome']) ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Telefono</label>
              <input type="text" name="telefono" class="form-control" value="<?= e($editUser['telefono']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Dipartimento</label>
              <?php $userDeps = user_departments($editUser); ?>
              <div class="border rounded p-2">
                <?php foreach($allowedDeps as $idx => $d): $depId = 'dep_edit_' . (int)$idx; ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="dipartimento[]" value="<?= e($d) ?>" id="<?= e($depId) ?>" <?= in_array($d, $userDeps, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= e($depId) ?>"><?= e($d) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Puoi selezionare uno o più dipartimenti.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= e($editUser['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nuova password (opzionale)</label>
              <input type="password" name="password" class="form-control" placeholder="Lascia vuoto per non cambiare">
            </div>

            <div class="col-md-6">
              <label class="form-label">Ruolo</label>
              <select name="role" class="form-select">
                <option value="editor" <?= $editUser['role']==='editor' ? 'selected' : '' ?>>Editor</option>
                <option value="admin"  <?= $editUser['role']==='admin'  ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-center">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Attivo</label>
              </div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Salva modifiche</button>
            <a class="btn btn-outline-secondary" href="<?= e($base) ?>/users.php">Torna all’elenco</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
