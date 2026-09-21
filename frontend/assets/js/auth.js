/**
 * CYBERGUARD CAMPUS — shared authentication logic.
 * The session itself is an HttpOnly cookie managed by the backend:
 * nothing about the user is stored in the browser, and no "signed in" flag exists here.
 * The backend is the only authority: a 401 from a protected endpoint ends the visit.
 */

import { ApiError, onUnauthorized, postLogin, postLogout } from './api.js';

export const WELCOME_PAGE = 'welcome.html';
export const LOGIN_PAGE = 'login.html';
export const HOME_PAGE = 'index.html';

export function isSessionError(error) {
  return error instanceof ApiError && error.kind === 'unauthorized';
}

export function isPermissionError(error) {
  return error instanceof ApiError && error.kind === 'forbidden';
}

let leaving = false;

/**
 * Session absent or expired (401): back to the public entry, once per page load.
 * replace() keeps the expired page out of the history; the URL carries nothing but the page.
 * Parallel 401s (e.g. the Dashboard's two requests) all land here; only the first navigates.
 */
function endSession() {
  if (leaving) {
    return;
  }

  leaving = true;
  window.location.replace(WELCOME_PAGE);
}

onUnauthorized(endSession);

/**
 * Signs in through POST /login and returns the user payload.
 * Throws ApiError (kind 'unauthorized' for rejected credentials).
 */
export async function signIn(identifier, password) {
  const payload = await postLogin(identifier, password);

  if (
    payload.success !== true
    || !payload.user
    || typeof payload.user.role !== 'string'
  ) {
    throw new ApiError('Unexpected authentication response.', { status: 200, kind: 'invalid-response' });
  }

  return payload.user;
}

/**
 * Signs out through POST /logout. Only once the backend has confirmed does the visit end, the
 * same way as any ended session (public entry, replace()). Nothing is stored in the browser, so
 * there is nothing to clear here. Throws ApiError when the sign-out was not confirmed.
 */
export async function signOut() {
  const payload = await postLogout();

  if (payload.success !== true) {
    throw new ApiError('Unexpected sign-out response.', { status: 200, kind: 'invalid-response' });
  }

  endSession();
}
