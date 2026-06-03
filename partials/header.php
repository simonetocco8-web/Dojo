<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/roles.php';
start_session();
$env = require __DIR__ . '/../config/env.php';
$user = current_user();
$base = rtrim($env['app']['base_url'] ?? '', '/');
$logo = $base . '/assets/dojo-logo.svg';
$favicon = $base . '/assets/favicon.svg';
$styleVersion = @filemtime(__DIR__ . '/../assets/style.css') ?: time();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($title) ? e($title) . ' · ' : '' ?>Dojo</title>
  <link rel="icon" type="image/svg+xml" href="<?= e($favicon) ?>">
  <link rel="shortcut icon" type="image/svg+xml" href="<?= e($favicon) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= e($base) ?>/assets/style.css?v=<?= (int)$styleVersion ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="dojo-layout">
  <aside class="dojo-sidebar d-none d-lg-flex flex-column p-3">
    <a class="navbar-brand d-flex justify-content-center mb-4" href="<?= e($base) ?>/index.php" aria-label="Dojo home">
      <img class="dojo-logo" src="<?= e($logo) ?>" width="128" height="48" alt="Dojo">
    </a>
    <ul class="nav nav-pills flex-column gap-1">
        <?php if($user): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/dashboard.php"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a></li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/tasks.php"><i class="bi bi-check2-square"></i><span>Task</span></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/transfere.php"><i class="bi bi-car-front"></i><span>Transfer</span></a></li>
        <?php endif; ?>
        <?php if ($user && user_is_bar_or_amministrazione($user)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="magazzinoSidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-seam"></i><span>Magazzino</span></a>
          <ul class="dropdown-menu" aria-labelledby="magazzinoSidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products.php"><i class="bi bi-tags"></i><span>Prodotti</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products_inactive.php"><i class="bi bi-archive"></i><span>Non Attivi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/carico.php"><i class="bi bi-box-arrow-in-down"></i><span>Carico</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/scarico.php"><i class="bi bi-box-arrow-up"></i><span>Scarico</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/suppliers/suppliers_list.php"><i class="bi bi-truck"></i><span>Fornitori</span></a></li>
          </ul>
        </li>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/days_off_list.php"><i class="bi bi-calendar-heart"></i><span>Giorni liberi</span></a></li>
        <?php endif; ?>
        <?php if ($user && (user_is_reception_or_amministrazione($user) || user_is_housekeeping($user))): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/riassetti.php"><i class="bi bi-stars"></i><span>Riassetti</span></a></li>
        <?php endif; ?>
        <?php if($user && user_can_send_sms($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/send_sms.php"><i class="bi bi-chat-dots"></i><span>Invia SMS</span></a></li>
        <?php endif; ?>
        <?php if($user): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/ai_chat.php"><i class="bi bi-robot"></i><span>AI Chat</span></a></li>
        <?php endif; ?>
        <?php if ($user && user_is_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/reports/daily_summary_pdf.php"><i class="bi bi-file-earmark-text"></i><span>Report Giornaliero</span></a></li>
        <?php endif; ?>
        <?php if($user && $user['role']==='admin'): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/users.php"><i class="bi bi-people"></i><span>Utenti</span></a></li>
        <?php endif; ?>
        <?php if($user && user_is_admin($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/settings.php"><i class="bi bi-gear"></i><span>Setting</span></a></li>
        <?php endif; ?>
    </ul>
    <?php if($user): ?>
      <div class="mt-auto pt-3 border-top border-secondary-subtle">
        <div class="text-white-50 small text-break mb-2"><i class="bi bi-person-circle me-1"></i><?= e($user['email']) ?></div>
        <a class="btn btn-outline-light btn-sm w-100" href="<?= e($base) ?>/logout.php"><i class="bi bi-box-arrow-right me-1"></i>Esci</a>
      </div>
    <?php endif; ?>
  </aside>

  <div class="dojo-content">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark d-lg-none">
      <div class="container-fluid">
        <a class="navbar-brand" href="<?= e($base) ?>/index.php" aria-label="Dojo home"><img class="dojo-logo" src="<?= e($logo) ?>" width="128" height="48" alt="Dojo"></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarColor" aria-controls="navbarColor" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarColor">
          <ul class="navbar-nav me-auto">
            <?php if($user): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/dashboard.php"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a></li>
            <?php endif; ?>
            <?php if($user && user_is_reception_or_amministrazione($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/tasks.php"><i class="bi bi-check2-square"></i><span>Task</span></a></li>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/transfere.php"><i class="bi bi-car-front"></i><span>Transfer</span></a></li>
            <?php endif; ?>
            <?php if ($user && user_is_bar_or_amministrazione($user)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="magazzinoDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-seam"></i><span>Magazzino</span></a>
              <ul class="dropdown-menu" aria-labelledby="magazzinoDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products.php"><i class="bi bi-tags"></i><span>Prodotti</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products_inactive.php"><i class="bi bi-archive"></i><span>Non Attivi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/carico.php"><i class="bi bi-box-arrow-in-down"></i><span>Carico</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/scarico.php"><i class="bi bi-box-arrow-up"></i><span>Scarico</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/suppliers/suppliers_list.php"><i class="bi bi-truck"></i><span>Fornitori</span></a></li>
              </ul>
            </li>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/days_off_list.php"><i class="bi bi-calendar-heart"></i><span>Giorni liberi</span></a></li>
            <?php endif; ?>
            <?php if ($user && (user_is_reception_or_amministrazione($user) || user_is_housekeeping($user))): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/riassetti.php"><i class="bi bi-stars"></i><span>Riassetti</span></a></li>
            <?php endif; ?>
            <?php if($user && user_can_send_sms($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/send_sms.php"><i class="bi bi-chat-dots"></i><span>Invia SMS</span></a></li>
            <?php endif; ?>
            <?php if($user): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/ai_chat.php"><i class="bi bi-robot"></i><span>AI Chat</span></a></li>
            <?php endif; ?>
            <?php if ($user && user_is_amministrazione($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/reports/daily_summary_pdf.php"><i class="bi bi-file-earmark-text"></i><span>Report Giornaliero</span></a></li>
            <?php endif; ?>
            <?php if($user && $user['role']==='admin'): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/users.php"><i class="bi bi-people"></i><span>Utenti</span></a></li>
            <?php endif; ?>
            <?php if($user && user_is_admin($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/settings.php"><i class="bi bi-gear"></i><span>Setting</span></a></li>
            <?php endif; ?>
          </ul>
          <div class="d-flex">
            <?php if($user): ?>
              <span class="navbar-text me-3 small"><?= e($user['email']) ?></span>
              <a class="btn btn-outline-light btn-sm" href="<?= e($base) ?>/logout.php"><i class="bi bi-box-arrow-right me-1"></i>Esci</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </nav>
    <main class="dojo-main container-fluid">
