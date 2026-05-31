<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/db.php';

start_session();
$pdo  = db();
$user = current_user();
if (!$user || !(is_admin() || user_has_department($user, 'Amministrazione'))) {
  http_response_code(403); exit('Permesso negato.');
}
$lbl = ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];

$id = (int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); exit('ID non valido.'); }

$forn = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
$forn->execute([$id]);
$s = $forn->fetch(PDO::FETCH_ASSOC);
if(!$s){ http_response_code(404); exit('Fornitore non trovato.'); }

$days = $pdo->prepare("SELECT day, kind FROM supplier_days WHERE supplier_id=?");
$days->execute([$id]);
$ord=[]; $del=[];
foreach($days as $d){ if($d['kind']==='order') $ord[]=(int)$d['day']; else $del[]=(int)$d['day']; }

$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_check($_POST['csrf'] ?? '')){ $msg='Token CSRF non valido.'; }
  else{
    $name = trim($_POST['name'] ?? '');
    $phone= trim($_POST['phone'] ?? '');
    $email= trim($_POST['email'] ?? '');
    $order_days    = array_map('intval', $_POST['order_days'] ?? []);
    $delivery_days = array_map('intval', $_POST['delivery_days'] ?? []);

    if($name===''){ $msg='Inserisci il nome.'; }
    else{
      try{
        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE suppliers SET name=?, phone=?, email=? WHERE id=?");
        $up->execute([$name, $phone ?: null, $email ?: null, $id]);

        // reset giorni e reinserisci
        $pdo->prepare("DELETE FROM supplier_days WHERE supplier_id=?")->execute([$id]);

        if ($order_days){
          $q = $pdo->prepare("INSERT INTO supplier_days (supplier_id, day, kind) VALUES (?,?, 'order')");
          foreach($order_days as $d){ if($d>=0 && $d<=6) $q->execute([$id, $d]); }
        }
        if ($delivery_days){
          $q = $pdo->prepare("INSERT INTO supplier_days (supplier_id, day, kind) VALUES (?,?, 'delivery')");
          foreach($delivery_days as $d){ if($d>=0 && $d<=6) $q->execute([$id, $d]); }
        }

        $pdo->commit();
        header('Location: suppliers_list.php?msg=updated'); exit;
      }catch(Throwable $e){
        $pdo->rollBack();
        $msg = 'Errore aggiornamento: '.e($e->getMessage());
      }
    }
  }
}

$title='Modifica Fornitore';
include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Modifica Fornitore</h1>
  <a class="btn btn-outline-secondary btn-sm" href="suppliers_list.php">Indietro</a>
</div>

<?php if($msg): ?><div class="alert alert-warning"><?= $msg ?></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nome *</label>
          <input type="text" name="name" class="form-control" value="<?= e($s['name']) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Telefono</label>
          <input type="text" name="phone" class="form-control" value="<?= e($s['phone'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= e($s['email'] ?? '') ?>">
        </div>
      </div>

      <div class="row g-3 mt-3">
        <div class="col-md-6">
          <label class="form-label mb-2">Giorni per Ordini</label><br>
          <?php foreach ($lbl as $i=>$l): ?>
            <label class="me-3 mb-2">
              <input type="checkbox" name="order_days[]" value="<?= $i ?>" <?= in_array($i,$ord,true)?'checked':''; ?>> <?= $l ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label mb-2">Giorni per Consegne</label><br>
          <?php foreach ($lbl as $i=>$l): ?>
            <label class="me-3 mb-2">
              <input type="checkbox" name="delivery_days[]" value="<?= $i ?>" <?= in_array($i,$del,true)?'checked':''; ?>> <?= $l ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">Salva</button>
        <a class="btn btn-outline-secondary" href="suppliers_list.php">Annulla</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
