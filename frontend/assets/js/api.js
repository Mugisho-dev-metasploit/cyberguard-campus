/**
 * CYBERGUARD CAMPUS — API client.
 * Single entry point for every HTTP call made by the frontend.
 * Backend contracts are consumed as-is: { success, message, data }.
 */

// Resolved from this module's own location, so no page hard-codes the project folder.
const API_BASE_URL = new URL('../../../backend/public/index.php', import.meta.url).pathname;
const REQUEST_TIMEOUT_MS = 15000;

/**
 * Typed API failure.
 * kind: 'unauthorized' | 'forbidden' | 'http' | 'network' | 'timeout' | 'invalid-response'
 */
export class ApiError extends Error {
  constructor(message, { status = 0, kind = 'http' } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.kind = kind;
  }
}

function errorForStatus(status) {
  if (status === 401) {
    return new ApiError('Authentication required.', { status, kind: 'unauthorized' });
  }

  if (status === 403) {
    return new ApiError('Insufficient permissions.', { status, kind: 'forbidden' });
  }

  return new ApiError(`Request failed with HTTP ${status}.`, { status, kind: 'http' });
}

async function readJson(response) {
  const contentType = response.headers.get('Content-Type') || '';

  if (!contentType.includes('application/json')) {
    return null;
  }

  try {
    return await response.json();
  } catch {
    return null;
  }
}

let unauthorizedHandler = null;

/**
 * Registers the single reaction to a 401 from a protected endpoint (auth.js: back to Welcome).
 * The ApiError is still thrown, so callers keep their own error handling.
 */
export function onUnauthorized(handler) {
  unauthorizedHandler = typeof handler === 'function' ? handler : null;
}

/** Only protected endpoints signal an ended session: a 401 from POST /login means wrong credentials. */
function isProtectedPath(path) {
  return path.startsWith('/api/');
}

/**
 * Performs a request and returns the parsed JSON payload.
 * Throws ApiError for network failures, timeouts, HTTP errors and non-JSON bodies.
 */
export async function request(method, path, body) {
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  const options = {
    method,
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    signal: controller.signal,
  };

  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  try {
    let response;

    try {
      response = await fetch(`${API_BASE_URL}${path}`, options);
    } catch (error) {
      if (error && error.name === 'AbortError') {
        throw new ApiError('The request timed out.', { kind: 'timeout' });
      }

      throw new ApiError('The API could not be reached.', { kind: 'network' });
    }

    if (!response.ok) {
      const error = errorForStatus(response.status);

      // 422 messages are written by the backend for the client (validation, workflow); keep them
      // so the page can show the precise reason. No other error body is ever read or shown.
      if (response.status === 422) {
        const body = await readJson(response);

        if (body && typeof body.message === 'string' && body.message.trim() !== '') {
          error.detail = body.message.trim().slice(0, 300);
        }
      }

      if (error.kind === 'unauthorized' && isProtectedPath(path) && unauthorizedHandler) {
        unauthorizedHandler();
      }

      throw error;
    }

    const payload = await readJson(response);

    if (payload === null || typeof payload !== 'object') {
      throw new ApiError('The API returned an unexpected response.', {
        status: response.status,
        kind: 'invalid-response',
      });
    }

    return payload;
  } finally {
    window.clearTimeout(timeoutId);
  }
}

/** GET helper for read endpoints: validates the envelope and returns `data`. */
async function getData(path) {
  const payload = await request('GET', path);

  if (payload.success !== true || !('data' in payload)) {
    throw new ApiError('The API returned an unexpected response.', { status: 200, kind: 'invalid-response' });
  }

  return payload.data;
}

export function getMetrics() {
  return getData('/api/metrics');
}

export function getAlerts() {
  return getData('/api/alerts');
}

export function getEvents() {
  return getData('/api/events');
}

export function getDevices() {
  return getData('/api/devices');
}

export function getIncidents() {
  return getData('/api/incidents');
}

/**
 * GET /api/incidents/{id} — one incident with its links and its history (oldest first):
 * resolves with `{ incident, history }`.
 */
export async function getIncident(id) {
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new ApiError('Invalid incident identifier.', { status: 400, kind: 'http' });
  }

  const data = await getData(`/api/incidents/${id}`);

  if (!data || typeof data !== 'object' || !data.incident || typeof data.incident !== 'object' || !Array.isArray(data.history)) {
    throw new ApiError('The API returned an unexpected response.', { status: 200, kind: 'invalid-response' });
  }

  return data;
}

/**
 * PATCH /api/incidents/{id} — existing contract: partial update with any of
 * title, description, severity, status, priority, assigned_to, resolution.
 * Resolves with the updated incident returned by the API (`data`).
 */
export async function updateIncident(id, fields) {
  if (!Number.isSafeInteger(id) || id <= 0) {
    throw new ApiError('Invalid incident identifier.', { status: 400, kind: 'http' });
  }

  const payload = await request('PATCH', `/api/incidents/${id}`, fields);

  if (payload.success !== true || !payload.data || typeof payload.data !== 'object') {
    throw new ApiError('The API returned an unexpected response.', { status: 200, kind: 'invalid-response' });
  }

  return payload.data;
}

/** POST /login — existing contract: { identifier, password } → { success, message, user }. */
export function postLogin(identifier, password) {
  return request('POST', '/login', { identifier, password });
}
