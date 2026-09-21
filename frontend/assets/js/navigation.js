/**
 * CYBERGUARD CAMPUS — navigation behavior.
 * Mobile drawer only: open/close, overlay, Escape, focus management,
 * scroll lock and keyboard isolation. No page or business logic here.
 */

// Must match the drawer breakpoint in layout.css / navigation.css.
const DRAWER_QUERY = window.matchMedia('(max-width: 919px)');

export function initNavigation() {
  const sidebar = document.getElementById('app-sidebar');
  const toggle = document.querySelector('.menu-toggle');
  const closeButton = document.querySelector('.sidebar-close');
  const overlay = document.querySelector('.nav-overlay');
  const background = [
    document.querySelector('.topbar'),
    document.getElementById('main-content'),
  ].filter(Boolean);

  if (!sidebar || !toggle || !overlay) {
    return;
  }

  let isOpen = false;

  function setOpen(open, { restoreFocus = false } = {}) {
    const isDrawer = DRAWER_QUERY.matches;
    isOpen = open && isDrawer;

    sidebar.classList.toggle('is-open', isOpen);
    overlay.classList.toggle('is-visible', isOpen);
    toggle.setAttribute('aria-expanded', String(isOpen));
    document.documentElement.classList.toggle('nav-open', isOpen);

    // A closed drawer leaves the tab order; an open drawer isolates the page behind it.
    sidebar.inert = isDrawer && !isOpen;
    background.forEach((node) => {
      node.inert = isOpen;
    });

    if (isOpen) {
      (closeButton || sidebar).focus();
    } else if (restoreFocus) {
      toggle.focus();
    }
  }

  toggle.addEventListener('click', () => setOpen(true));

  if (closeButton) {
    closeButton.addEventListener('click', () => setOpen(false, { restoreFocus: true }));
  }

  overlay.addEventListener('click', () => setOpen(false, { restoreFocus: true }));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen) {
      setOpen(false, { restoreFocus: true });
    }
  });

  DRAWER_QUERY.addEventListener('change', () => setOpen(false));

  setOpen(false);
}
