<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
start_session();
$env = require __DIR__ . '/../config/env.php';
$user = current_user();
$base = rtrim($env['app']['base_url'] ?? '', '/');
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($title) ? e($title) . ' · ' : '' ?>Admin Starter</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= e($base) ?>/assets/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= e($base) ?>/index.php">Admin</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarColor" aria-controls="navbarColor" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarColor">
      <ul class="navbar-nav me-auto">
        <?php if($user && $user['role']==='admin'): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/users.php">Utenti</a></li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/tasks.php">Task</a></li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/transfere.php">Transfer</a></li>
        <?php endif; ?>
        <?php if($user && $user['role']==='admin'): ?>
        <!--<li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/ewelink/devices.php">eWeLink</a></li> -->
        <?php endif; ?>
        <?php if (user_is_bar_or_amministrazione($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/days_off_list.php">Giorni liberi</a></li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="magazzinoDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Magazzino</a>
              <ul class="dropdown-menu" aria-labelledby="magazzinoDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products.php">Prodotti</a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/carico.php">Carico</a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/scarico.php">Scarico</a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/suppliers/suppliers_list.php">Fornitori</a></li>
              </ul>
            </li>
        <?php endif; ?>
        <?php if ($user && user_is_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/reports/daily_summary_pdf.php">Report Giornaliero</a></li>
        <?php endif; ?>
        <?php if ($user && (user_is_reception_or_amministrazione($user) || user_is_housekeeping($user))): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/riassetti.php">Riassetti</a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex">
        <?php if($user): ?>
          <span class="navbar-text me-3 small"><?= e($user['email']) ?></span>
          <a class="btn btn-outline-light btn-sm" href="<?= e($base) ?>/logout.php">Esci</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<main class="container py-4">
