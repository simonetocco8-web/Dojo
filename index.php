<?php
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/db.php';

start_session();
$env  = require __DIR__ . '/config/env.php';
$base = rtrim($env['app']['base_url'] ?? '', '/');
$user = current_user();

// SE SEI GIÀ LOGGATO → DASHBOARD
if ($user) {
  header('Location: dashboard.php');  
  exit;
}else{
     header('Location: login.php?msg=auth'); 
  exit; 
}

// QUI mostri il form di login, niente redirect se non loggato!
$title = 'Login';
include __DIR__ . '/partials/header.php';
?>
<!-- form di login qui -->
<?php include __DIR__ . '/partials/footer.php'; ?>