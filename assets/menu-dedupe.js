(function () {
  function dedupeTramontoDayMenus() {
    const allMenus = Array.from(document.querySelectorAll('.dojo-tramontoday-menu, .nav-item.dropdown')).filter(function (menu) {
      const label = menu.querySelector('.dropdown-toggle span');
      return menu.classList.contains('dojo-tramontoday-menu') || (label && label.textContent.trim() === 'TramontoDay');
    });
    if (allMenus.length <= 1) return;

    const isDesktop = window.matchMedia('(min-width: 992px)').matches;
    let kept = false;

    allMenus.forEach(function (menu) {
      const isSidebarMenu = Boolean(menu.closest('.dojo-sidebar'));
      const isNavbarMenu = Boolean(menu.closest('.navbar'));
      const belongsToCurrentLayout = isDesktop
        ? (isSidebarMenu || (!isNavbarMenu && !menu.classList.contains('dojo-mobile-only')))
        : (isNavbarMenu || (!isSidebarMenu && !menu.classList.contains('dojo-desktop-only')));

      if (belongsToCurrentLayout && !kept) {
        menu.style.display = '';
        kept = true;
      } else {
        menu.style.display = 'none';
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dedupeTramontoDayMenus);
  } else {
    dedupeTramontoDayMenus();
  }
  window.addEventListener('resize', dedupeTramontoDayMenus);
})();
