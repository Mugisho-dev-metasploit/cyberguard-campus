/**
 * CYBERGUARD CAMPUS — application shell.
 * Single source of truth for branding, sidebar, navigation, mobile topbar,
 * overlay and drawer close button. Pages only provide <main id="main-content">.
 */

import { el, icon } from './format.js';
import { HOME_PAGE } from './auth.js';

const EMBLEM_SRC = new URL('../img/brand/cyberguard-campus-emblem.png', import.meta.url).pathname;
const SIDEBAR_ID = 'app-sidebar';

/**
 * Navigation configuration.
 * An item without `href` is rendered as unavailable (not a link).
 * Enable a page in a later ticket by adding its `href`.
 */
export const NAVIGATION = [
  {
    label: 'Operations',
    items: [
      { id: 'dashboard', label: 'Dashboard', icon: 'dashboard', href: 'index.html' },
      { id: 'events', label: 'Events', icon: 'events', href: 'events.html' },
      { id: 'alerts', label: 'Alerts', icon: 'alerts', href: 'alerts.html' },
      { id: 'incidents', label: 'Incidents', icon: 'incidents', href: 'incidents.html' },
    ],
  },
  {
    label: 'Infrastructure',
    items: [
      { id: 'devices', label: 'Devices', icon: 'devices', href: 'devices.html' },
      { id: 'monitoring', label: 'Monitoring', icon: 'monitoring', href: 'monitoring.html' },
    ],
  },
];

function findPage(pageId) {
  for (const group of NAVIGATION) {
    const item = group.items.find((entry) => entry.id === pageId);

    if (item) {
      return item;
    }
  }

  return null;
}

function createBrand(className) {
  const emblem = el('img', {
    className: 'brand-emblem',
    attrs: { src: EMBLEM_SRC, alt: '', width: 40, height: 40, decoding: 'async' },
  });

  return el('a', {
    className: `brand ${className}`,
    attrs: { href: HOME_PAGE, 'aria-label': 'CYBERGUARD CAMPUS — Security Overview' },
  }, [
    emblem,
    el('span', { className: 'brand-text', attrs: { 'aria-hidden': 'true' } }, [
      el('span', { className: 'brand-name', text: 'CYBERGUARD' }),
      el('span', { className: 'brand-product', text: 'CAMPUS' }),
    ]),
  ]);
}

function createNavItem(item, activeId) {
  const content = [icon(item.icon), el('span', { text: item.label })];

  if (!item.href) {
    content.push(
      el('span', { className: 'nav-soon', text: 'Soon', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'visually-hidden', text: '(not yet available)' }),
    );

    return el('li', {}, [
      el('span', {
        className: 'nav-item is-disabled',
        attrs: { role: 'link', 'aria-disabled': 'true' },
      }, content),
    ]);
  }

  const link = el('a', { className: 'nav-item', attrs: { href: item.href } }, content);

  if (item.id === activeId) {
    link.setAttribute('aria-current', 'page');
  }

  return el('li', {}, [link]);
}

function createSidebar(activeId) {
  const closeButton = el('button', {
    className: 'sidebar-close',
    attrs: { type: 'button', 'aria-label': 'Close navigation' },
  }, [icon('close')]);

  const groups = NAVIGATION.map((group, index) => {
    const labelId = `nav-group-${index}`;

    return el('div', { className: 'nav-group' }, [
      el('p', { className: 'nav-group-label', text: group.label, attrs: { id: labelId } }),
      el('ul', { attrs: { 'aria-labelledby': labelId } },
        group.items.map((item) => createNavItem(item, activeId))),
    ]);
  });

  return el('aside', { className: 'sidebar', attrs: { id: SIDEBAR_ID } }, [
    el('div', { className: 'sidebar-header' }, [createBrand('sidebar-brand'), closeButton]),
    el('nav', { className: 'sidebar-nav', attrs: { 'aria-label': 'Main' } }, groups),
    el('div', { className: 'sidebar-footer' }, [
      el('strong', { text: 'CYBERGUARD CAMPUS' }),
      el('span', { text: 'Security operations console' }),
    ]),
  ]);
}

function createTopbar(page) {
  const toggle = el('button', {
    className: 'menu-toggle',
    attrs: {
      type: 'button',
      'aria-label': 'Open navigation',
      'aria-controls': SIDEBAR_ID,
      'aria-expanded': 'false',
    },
  }, [icon('menu')]);

  const breadcrumb = el('nav', { className: 'breadcrumb', attrs: { 'aria-label': 'Breadcrumb' } }, [
    el('ol', { className: 'breadcrumb-list' }, [
      el('li', { text: 'Security Operations' }),
      el('li', { text: page ? page.label : '', attrs: { 'aria-current': 'page' } }),
    ]),
  ]);

  return el('header', { className: 'topbar' }, [
    toggle,
    createBrand('topbar-brand'),
    breadcrumb,
  ]);
}

/** Renders the shell into the mount point for the page identified by body[data-page]. */
export function renderShell(mount, pageId) {
  const page = findPage(pageId);

  mount.append(
    el('a', { className: 'skip-link', text: 'Skip to content', attrs: { href: '#main-content' } }),
    createTopbar(page),
    el('div', { className: 'nav-overlay', attrs: { 'aria-hidden': 'true' } }),
    createSidebar(page ? page.id : null),
  );
}
