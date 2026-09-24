(function () {
  function dedupeMenu(labelText) {
    const allMenus = Array.from(document.querySelectorAll('.nav-item')).filter(function (menu) {
      const label = menu.querySelector(':scope > .nav-link span');
      return label && label.textContent.trim() === labelText;
    });
    const isDesktop = window.matchMedia('(min-width: 992px)').matches;
    const currentLayoutMenus = allMenus.filter(function (menu) {
      const isSidebarMenu = Boolean(menu.closest('.dojo-sidebar'));
      const isNavbarMenu = Boolean(menu.closest('.navbar'));
      return isDesktop
        ? (isSidebarMenu || (!isNavbarMenu && !menu.classList.contains('dojo-mobile-only')))
        : (isNavbarMenu || (!isSidebarMenu && !menu.classList.contains('dojo-desktop-only')));
    });
    const preferredMenu = currentLayoutMenus.find(function (menu) {
      return menu.classList.contains('dropdown') && menu.querySelector(':scope > .dropdown-menu');
    }) || currentLayoutMenus[0];

    allMenus.forEach(function (menu) {
      menu.style.display = menu === preferredMenu ? '' : 'none';
    });
  }

  function dedupeNavigationMenus() {
    dedupeMenu('TramontoDay');
    dedupeMenu('Parcheggi');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dedupeNavigationMenus);
  } else {
    dedupeNavigationMenus();
  }
  window.addEventListener('resize', dedupeNavigationMenus);
})();
