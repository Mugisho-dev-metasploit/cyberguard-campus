/**
 * CYBERGUARD CAMPUS — Sign in.
 * POST /login { identifier, password } through the shared API client (auth.signIn → api.request).
 * The backend sets the HttpOnly session cookie; this page never reads, writes or stores it,
 * never stores the user, and never logs, echoes or persists the password.
 *
 * Backend contract (verified): 401 for every rejected sign-in (same message, no account
 * enumeration), 200 with the user on success. 403 / 422 / 429 are not returned today;
 * they are mapped defensively so an unexpected status still gets a clear message.
 */

import { HOME_PAGE, signIn } from '../auth.js';

const dom = {
  form: document.getElementById('login-form'),
  identifier: document.getElementById('identifier'),
  password: document.getElementById('password'),
  identifierError: document.getElementById('identifier-error'),
  passwordError: document.getElementById('password-error'),
  toggle: document.getElementById('password-toggle'),
  capsHint: document.getElementById('caps-hint'),
  submit: document.getElementById('login-submit'),
  submitText: document.querySelector('.login-submit-text'),
  feedback: document.getElementById('login-feedback'),
  progress: document.getElementById('login-progress'),
};

const SUBMIT_LABEL = 'Sign in';
const BUSY_LABEL = 'Signing in…';

let submitting = false;

/* Field errors --------------------------------------------------------------------- */

function describedBy(input, ids) {
  const list = ids.filter(Boolean).join(' ');

  if (list) {
    input.setAttribute('aria-describedby', list);
  } else {
    input.removeAttribute('aria-describedby');
  }
}

function setFieldError(input, errorNode, message) {
  const hintIds = input === dom.identifier ? ['identifier-hint'] : [dom.capsHint.hidden ? '' : 'caps-hint'];

  if (message) {
    errorNode.textContent = message;
    errorNode.hidden = false;
    input.setAttribute('aria-invalid', 'true');
    describedBy(input, [errorNode.id, ...hintIds]);
  } else {
    errorNode.textContent = '';
    errorNode.hidden = true;
    input.removeAttribute('aria-invalid');
    describedBy(input, hintIds);
  }
}

function clearFieldErrors() {
  setFieldError(dom.identifier, dom.identifierError, '');
  setFieldError(dom.password, dom.passwordError, '');
}

/* Form-level feedback (announced by role="alert") ------------------------------------- */

function showFeedback(message) {
  dom.feedback.textContent = message;
  dom.feedback.hidden = false;
}

function hideFeedback() {
  dom.feedback.textContent = '';
  dom.feedback.hidden = true;
}

/**
 * User-facing copy for a failed sign-in. Never shows the backend message or any raw detail,
 * and never says whether an identifier exists.
 */
function signInFailure(error) {
  const kind = error && error.kind;
  const status = error && error.status;

  if (kind === 'unauthorized') {
    return { message: 'Sign-in failed. Check your identifier and password, then try again.', field: 'password' };
  }

  if (kind === 'forbidden') {
    return { message: 'This account is not allowed to access CYBERGUARD CAMPUS. Contact your security administrator.' };
  }

  if (kind === 'network' || kind === 'timeout') {
    return { message: 'The server could not be reached. Check your connection and try again.' };
  }

  if (kind === 'invalid-response') {
    return { message: 'The server answered in an unexpected way. Try again in a moment.' };
  }

  if (status === 429) {
    return { message: 'Too many sign-in attempts. Wait a moment, then try again.' };
  }

  if (status === 400 || status === 422) {
    return { message: 'The sign-in request was not accepted. Check both fields and try again.', field: 'identifier' };
  }

  if (status >= 500) {
    return { message: 'The server could not complete the sign-in. Try again in a moment.' };
  }

  return { message: 'Sign-in could not be completed. Try again in a moment.' };
}

/* Busy state ------------------------------------------------------------------------ */

function setBusy(busy) {
  submitting = busy;
  dom.submit.disabled = busy;
  dom.submit.setAttribute('aria-busy', String(busy));
  dom.submitText.textContent = busy ? BUSY_LABEL : SUBMIT_LABEL;
  // Read-only rather than disabled: values stay announced and focusable.
  dom.identifier.readOnly = busy;
  dom.password.readOnly = busy;
  dom.toggle.disabled = busy;
}

/* Password visibility & Caps Lock ------------------------------------------------------- */

function setPasswordVisible(visible) {
  dom.password.type = visible ? 'text' : 'password';
  dom.toggle.setAttribute('aria-pressed', String(visible));
  dom.toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
  dom.toggle.querySelector('.password-toggle-text').textContent = visible ? 'Hide' : 'Show';
}

function updateCapsLock(event) {
  if (typeof event.getModifierState !== 'function') {
    return;
  }

  const on = event.getModifierState('CapsLock');

  if (dom.capsHint.hidden === !on) {
    return;
  }

  dom.capsHint.hidden = !on;
  describedBy(dom.password, [dom.password.getAttribute('aria-invalid') === 'true' ? 'password-error' : '', on ? 'caps-hint' : '']);
}

/* Submission --------------------------------------------------------------------------- */

function validate(identifier, password) {
  const identifierMessage = identifier ? '' : 'Enter your identifier.';
  const passwordMessage = password ? '' : 'Enter your password.';

  setFieldError(dom.identifier, dom.identifierError, identifierMessage);
  setFieldError(dom.password, dom.passwordError, passwordMessage);

  if (identifierMessage) {
    dom.identifier.focus();
    return false;
  }

  if (passwordMessage) {
    dom.password.focus();
    return false;
  }

  return true;
}

async function handleSubmit(event) {
  event.preventDefault();

  // Enter and click both land here: a second submission is ignored while one is running.
  if (submitting) {
    return;
  }

  hideFeedback();

  // The identifier is trimmed; the password is sent exactly as typed.
  const identifier = dom.identifier.value.trim();
  const password = dom.password.value;

  if (!validate(identifier, password)) {
    return;
  }

  setBusy(true);
  dom.progress.textContent = 'Signing in.';

  try {
    await signIn(identifier, password);
  } catch (error) {
    const failure = signInFailure(error);
    setBusy(false);
    dom.progress.textContent = '';

    if (failure.field === 'password') {
      // A rejected password is cleared so it is never left on screen to retype over.
      dom.password.value = '';
      setPasswordVisible(false);
      setFieldError(dom.password, dom.passwordError, 'Re-enter your password.');
    } else if (failure.field === 'identifier') {
      setFieldError(dom.identifier, dom.identifierError, 'Check your identifier.');
    }

    showFeedback(failure.message);
    (failure.field === 'identifier' ? dom.identifier : dom.password).focus();
    return;
  }

  // Success is decided by the backend (2xx + session cookie). Nothing about the user is kept.
  dom.password.value = '';
  setPasswordVisible(false);
  dom.submitText.textContent = 'Signed in';
  dom.progress.textContent = 'Signed in. Opening the dashboard.';
  window.location.assign(HOME_PAGE);
}

/* Wiring ------------------------------------------------------------------------------- */

function init() {
  if (!dom.form || !dom.identifier || !dom.password || !dom.submit) {
    return;
  }

  dom.form.addEventListener('submit', handleSubmit);

  dom.toggle.addEventListener('click', () => {
    setPasswordVisible(dom.password.type === 'password');
    dom.password.focus();
  });

  dom.password.addEventListener('keydown', updateCapsLock);
  dom.password.addEventListener('keyup', updateCapsLock);
  dom.password.addEventListener('blur', () => {
    dom.capsHint.hidden = true;
    describedBy(dom.password, [dom.password.getAttribute('aria-invalid') === 'true' ? 'password-error' : '']);
  });

  // Typing again clears that field's error.
  dom.identifier.addEventListener('input', () => {
    if (dom.identifier.getAttribute('aria-invalid') === 'true') setFieldError(dom.identifier, dom.identifierError, '');
  });
  dom.password.addEventListener('input', () => {
    if (dom.password.getAttribute('aria-invalid') === 'true') setFieldError(dom.password, dom.passwordError, '');
  });

  clearFieldErrors();
}

init();
