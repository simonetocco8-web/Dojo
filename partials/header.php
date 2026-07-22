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
$currentPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
function nav_path_is_current(array $paths, string $currentPath): bool {
  return in_array($currentPath, $paths, true);
}
$tramontoDayMenuOpen = nav_path_is_current([
  'tramontoday_availability.php',
  'tramontoday_bookings.php',
  'tramontoday_today.php',
  'tramontoday_booking_create.php',
  'tramontoday_settings.php',
  'tramontoday_reports.php',
], $currentPath);
$trasportiMenuOpen = nav_path_is_current(['transfere.php', 'voli.php', 'treni.php'], $currentPath);
$magazzinoMenuOpen = str_starts_with($currentPath, 'product_') || nav_path_is_current([
  'products.php',
  'products_inactive.php',
  'carico.php',
  'scarico.php',
  'statistiche.php',
  'suppliers_list.php',
], $currentPath);
$personaleMenuOpen = nav_path_is_current(['days_off_list.php', 'days_off_create.php', 'overtime.php', 'overtime_monthly.php'], $currentPath);
$utentiMenuOpen = nav_path_is_current(['users.php', 'user_create.php', 'user_edit.php'], $currentPath);
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
        <?php if($user): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDaySidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
          <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDaySidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDaySidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
          <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDaySidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item dropdown dojo-desktop-only">
          <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDaySidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
          <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDaySidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item dropdown dojo-tramontoday-menu dojo-desktop-only">
          <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDaySidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
          <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDaySidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if($user && user_is_reception_or_amministrazione($user)): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/tasks.php"><i class="bi bi-check2-square"></i><span>Task</span></a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $trasportiMenuOpen ? 'active' : '' ?>" href="#" id="trasportiSidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $trasportiMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-car-front"></i><span>Trasporti</span></a>
          <ul class="dropdown-menu <?= $trasportiMenuOpen ? 'show' : '' ?>" aria-labelledby="trasportiSidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/transfere.php"><i class="bi bi-car-front"></i><span>Transfer</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/voli.php"><i class="bi bi-airplane"></i><span>Voli</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/treni.php"><i class="bi bi-train-front"></i><span>Treni</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if ($user && user_is_bar_or_amministrazione($user)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $magazzinoMenuOpen ? 'active' : '' ?>" href="#" id="magazzinoSidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $magazzinoMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-box-seam"></i><span>Magazzino</span></a>
          <ul class="dropdown-menu <?= $magazzinoMenuOpen ? 'show' : '' ?>" aria-labelledby="magazzinoSidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products.php"><i class="bi bi-tags"></i><span>Prodotti</span></a></li>
            <?php if ($user && user_is_amministrazione($user)): ?>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/product_categories.php"><i class="bi bi-folder2-open"></i><span>Categorie Prodotti</span></a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products_inactive.php"><i class="bi bi-archive"></i><span>Non Attivi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/carico.php"><i class="bi bi-box-arrow-in-down"></i><span>Carico</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/scarico.php"><i class="bi bi-box-arrow-up"></i><span>Scarico</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/statistiche.php"><i class="bi bi-graph-up"></i><span>Statistiche</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/suppliers/suppliers_list.php"><i class="bi bi-truck"></i><span>Fornitori</span></a></li>
          </ul>
        </li>
        <?php endif; ?>
        <?php if ($user && user_is_amministrazione($user)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $personaleMenuOpen ? 'active' : '' ?>" href="#" id="personaleSidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $personaleMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-person-lines-fill"></i><span>Personale</span></a>
          <ul class="dropdown-menu <?= $personaleMenuOpen ? 'show' : '' ?>" aria-labelledby="personaleSidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/days_off_list.php"><i class="bi bi-calendar-heart"></i><span>Giorni liberi</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/overtime.php"><i class="bi bi-clock-history"></i><span>Straordinari</span></a></li>
            <li><a class="dropdown-item" href="<?= e($base) ?>/overtime_monthly.php"><i class="bi bi-calculator"></i><span>Calcolo Mensile</span></a></li>
          </ul>
        </li>
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
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $utentiMenuOpen ? 'active' : '' ?>" href="#" id="utentiSidebarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $utentiMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-person-gear"></i><span>Utenti</span></a>
          <ul class="dropdown-menu <?= $utentiMenuOpen ? 'show' : '' ?>" aria-labelledby="utentiSidebarDropdown">
            <li><a class="dropdown-item" href="<?= e($base) ?>/users.php"><i class="bi bi-people"></i><span>Personale</span></a></li>
          </ul>
        </li>
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
            <?php if($user): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDayDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
              <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDayDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if($user && user_is_reception_or_amministrazione($user)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDayDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
              <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDayDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if($user && user_is_reception_or_amministrazione($user)): ?>
            <li class="nav-item dropdown dojo-mobile-only">
              <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDayDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
              <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDayDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if($user && user_is_reception_or_amministrazione($user)): ?>
            <li class="nav-item dropdown dojo-tramontoday-menu dojo-mobile-only">
              <a class="nav-link dropdown-toggle <?= $tramontoDayMenuOpen ? 'active' : '' ?>" href="#" id="tramontoDayDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $tramontoDayMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-sun"></i><span>TramontoDay</span></a>
              <ul class="dropdown-menu <?= $tramontoDayMenuOpen ? 'show' : '' ?>" aria-labelledby="tramontoDayDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_availability.php"><i class="bi bi-calendar-week"></i><span>Calendario disponibilità</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_bookings.php"><i class="bi bi-journal-check"></i><span>Prenotazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_today.php"><i class="bi bi-door-open"></i><span>Accessi di oggi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_booking_create.php"><i class="bi bi-plus-circle"></i><span>Nuova prenotazione/accesso</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_settings.php"><i class="bi bi-sliders"></i><span>Tariffe e impostazioni</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/tramontoday_reports.php"><i class="bi bi-bar-chart-line"></i><span>Report</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if($user && user_is_reception_or_amministrazione($user)): ?>
            <li class="nav-item"><a class="nav-link" href="<?= e($base) ?>/tasks.php"><i class="bi bi-check2-square"></i><span>Task</span></a></li>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $trasportiMenuOpen ? 'active' : '' ?>" href="#" id="trasportiDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $trasportiMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-car-front"></i><span>Trasporti</span></a>
              <ul class="dropdown-menu <?= $trasportiMenuOpen ? 'show' : '' ?>" aria-labelledby="trasportiDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/transfere.php"><i class="bi bi-car-front"></i><span>Transfer</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/voli.php"><i class="bi bi-airplane"></i><span>Voli</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/treni.php"><i class="bi bi-train-front"></i><span>Treni</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if ($user && user_is_bar_or_amministrazione($user)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $magazzinoMenuOpen ? 'active' : '' ?>" href="#" id="magazzinoDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $magazzinoMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-box-seam"></i><span>Magazzino</span></a>
              <ul class="dropdown-menu <?= $magazzinoMenuOpen ? 'show' : '' ?>" aria-labelledby="magazzinoDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products.php"><i class="bi bi-tags"></i><span>Prodotti</span></a></li>
                <?php if ($user && user_is_amministrazione($user)): ?>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/product_categories.php"><i class="bi bi-folder2-open"></i><span>Categorie Prodotti</span></a></li>
                <?php endif; ?>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/products_inactive.php"><i class="bi bi-archive"></i><span>Non Attivi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/carico.php"><i class="bi bi-box-arrow-in-down"></i><span>Carico</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/scarico.php"><i class="bi bi-box-arrow-up"></i><span>Scarico</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/inventory/statistiche.php"><i class="bi bi-graph-up"></i><span>Statistiche</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/suppliers/suppliers_list.php"><i class="bi bi-truck"></i><span>Fornitori</span></a></li>
              </ul>
            </li>
            <?php endif; ?>
            <?php if ($user && user_is_amministrazione($user)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $personaleMenuOpen ? 'active' : '' ?>" href="#" id="personaleDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $personaleMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-person-lines-fill"></i><span>Personale</span></a>
              <ul class="dropdown-menu <?= $personaleMenuOpen ? 'show' : '' ?>" aria-labelledby="personaleDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/days_off_list.php"><i class="bi bi-calendar-heart"></i><span>Giorni liberi</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/overtime.php"><i class="bi bi-clock-history"></i><span>Straordinari</span></a></li>
                <li><a class="dropdown-item" href="<?= e($base) ?>/overtime_monthly.php"><i class="bi bi-calculator"></i><span>Calcolo Mensile</span></a></li>
              </ul>
            </li>
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
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= $utentiMenuOpen ? 'active' : '' ?>" href="#" id="utentiDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="<?= $utentiMenuOpen ? 'true' : 'false' ?>"><i class="bi bi-person-gear"></i><span>Utenti</span></a>
              <ul class="dropdown-menu <?= $utentiMenuOpen ? 'show' : '' ?>" aria-labelledby="utentiDropdown">
                <li><a class="dropdown-item" href="<?= e($base) ?>/users.php"><i class="bi bi-people"></i><span>Personale</span></a></li>
              </ul>
            </li>
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
