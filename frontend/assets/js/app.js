/**
 * CYBERGUARD CAMPUS — application bootstrap.
 * Mounts the shared shell for the page named by <body data-page="…">,
 * then wires the navigation behavior. Page logic lives in pages/*.js.
 * Only protected pages load this module (Welcome and Login do not).
 */

import { renderShell } from './shell.js';
import { initNavigation } from './navigation.js';

const mount = document.getElementById('app-shell');

if (mount) {
  renderShell(mount, document.body.dataset.page);
  initNavigation();

  // Back/forward cache: a protected page brought back from history shows data from an earlier
  // visit. Reload it so its API calls run again — an ended session then gets its 401 (→ Welcome).
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      window.location.reload();
    }
  });
}
