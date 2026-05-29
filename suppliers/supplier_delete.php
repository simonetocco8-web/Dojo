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

if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); exit('Metodo non consentito.'); }
if(!csrf_check($_POST['csrf'] ?? '')){ http_response_code(400); exit('Token CSRF non valido.'); }

$id = (int)($_POST['id'] ?? 0);
if($id<=0){ http_response_code(400); exit('ID non valido.'); }

$del = $pdo->prepare("DELETE FROM suppliers WHERE id=?");
$del->execute([$id]);

header('Location: suppliers_list.php?msg=deleted');
exit;
