<?php
require_once __DIR__ . '/_role_guard.php';
if (!$user) { 
    http_response_code(401);
  echo json_encode(['error'=>'unauthorized']);
  exit;
    
}     // redirect RELATIVO
header('Content-Type: application/json; charset=utf-8');
$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$st = $pdo->prepare("
  SELECT p.id, p.title,
    COALESCE((SELECT SUM(qty) FROM stock_levels WHERE product_id=p.id),0) AS stock
  FROM products p
  WHERE COALESCE(p.is_active, 1) = 1 AND (p.title LIKE ? OR p.ean13 = ?)
  ORDER BY p.title ASC
  LIMIT 20
");
$like = '%'.$q.'%';
$st->execute([$like, $q]);
$rows = $st->fetchAll();
echo json_encode(array_map(function($r){
  return ['id'=>(int)$r['id'],'title'=>$r['title'],'stock'=>(float)$r['stock']];
}, $rows));
