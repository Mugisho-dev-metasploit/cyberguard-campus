/**
 * CYBERGUARD CAMPUS — application bootstrap.
 * Mounts the shared shell for the page named by <body data-page="…">,
 * then wires the navigation behavior. Page logic lives in pages/*.js.
 * Only protected pages load this module (Welcome and Login do not).
 */

import { renderShell } from './shell.js';
import { initNavigation } from './navigation.js';
import { signOut } from './auth.js';

const SIGN_OUT_LABEL = 'Sign out';

/**
 * Sidebar "Sign out": one POST /logout at a time; the page is left only after the backend
 * confirmed. On failure nothing claims success: a generic message, and the button works again
 * (the backend treats a repeated logout as a no-op, so retrying is safe).
 */
function initSignOut() {
  const button = document.querySelector('.sidebar-signout');
  const status = document.querySelector('.sidebar-signout-status');
  const label = button ? button.querySelector('span') : null;

  if (!button || !status || !label) {
    return;
  }

  let pending = false;

  button.addEventListener('click', async (event) => {
    event.preventDefault();

    if (pending) {
      return;
    }

    pending = true;
    button.disabled = true;
    label.textContent = 'Signing out…';
    status.textContent = '';

    try {
      // On success the page navigates away; the button stays disabled until then.
      await signOut();
    } catch {
      pending = false;
      button.disabled = false;
      label.textContent = SIGN_OUT_LABEL;
      status.textContent = 'Sign-out could not be confirmed by the server. Check your connection and try again.';
      button.focus();
    }
  });
}

const mount = document.getElementById('app-shell');

if (mount) {
  renderShell(mount, document.body.dataset.page);
  initNavigation();
  initSignOut();

  // Back/forward cache: a protected page brought back from history shows data from an earlier
  // visit. Reload it so its API calls run again — an ended session then gets its 401 (→ Welcome).
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      window.location.reload();
    }
  });
}
