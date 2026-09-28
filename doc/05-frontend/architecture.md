# Frontend architecture

## Module layers

```text
pages/<page>.js     page logic: load, render, interact
   ↓ uses
api.js              fetch wrapper, typed ApiError, single 401 hook
auth.js             signIn / signOut / endSession, public page constants
shell.js            sidebar + topbar for protected pages
navigation.js       drawer behaviour (mobile)
format.js           dates, counts, severity and status tags, DOM builders
charts.js           chart builders (columns, bars, heatmap, matrix) + table twin
```

`app.js` is the bootstrap for protected pages: it mounts the shell, wires navigation, wires the
sign-out button, and reloads a page restored from the back/forward cache.

## `api.js`

- Base URL derived from the module's own location, so no page hard-codes the project folder.
- 15-second timeout via `AbortController`.
- `ApiError` with a `kind`: `unauthorized`, `forbidden`, `http`, `network`, `timeout`,
  `invalid-response`.
- A single `onUnauthorized` hook, registered by `auth.js`; only a 401 from a path starting with
  `/api/` triggers it (a 401 from `POST /login` means wrong credentials).
- Exposed calls: `getMetrics`, `getAlerts`, `getEvents`, `getDevices`, `getIncidents`,
  `getIncident(id)`, `updateIncident(id, fields)`, `postLogin`, `postLogout`.
- Responses are validated: the envelope must carry `success: true` and `data`, otherwise the
  call fails with `invalid-response`.

## `auth.js`

- `WELCOME_PAGE`, `LOGIN_PAGE`, `HOME_PAGE` constants (fixed relative pages, so no redirect can
  be influenced from outside).
- `endSession()` navigates to the welcome page once per page load (`replace`, so the expired
  page leaves the history).
- `signIn()` validates the payload shape; `signOut()` only ends the visit after the backend
  confirmed the sign-out.

## Rendering conventions

- `format.js` builds elements (`el`, `icon`, `severityTag`, `statusTag`, `stateBlock`) and
  formats dates, relative times and counts; `describeError` turns an `ApiError` into
  user-facing copy.
- Empty, loading and error states are explicit blocks, not blank tables.
- Charts always ship a "View as table" twin, so no value is available only as a picture.
