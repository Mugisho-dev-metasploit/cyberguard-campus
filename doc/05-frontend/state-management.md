# State management

There is no state library and no client-side store. State is deliberately thin.

| State | Where it lives | Lifetime |
|---|---|---|
| Authentication | Server-side session, referenced by an `HttpOnly` cookie | 6 hours |
| Current page | The URL | — |
| Loaded data | Local variables inside the page module | Until reload |
| Selected incident | Page module state, reflected in the detail panel | Until reload |
| UI state (drawer open, tooltip) | DOM classes and attributes | Until reload |

## What the interface never keeps

- No user object, no role copy, no permissions cache: the backend decides on every request.
- No token, no session identifier in JavaScript.
- No `localStorage` or `sessionStorage` use anywhere (verified across `frontend/assets/js`).

The consequence is intentional: reloading a page re-reads everything from the API, and a session
that ended server-side cannot leave the interface in a "signed in" state.

## Reaction to a lost session

```text
any /api/* call → 401
      ↓
api.js calls the single onUnauthorized hook
      ↓
auth.js endSession(): location.replace(welcome.html), once per page load
```

Pages restored from the back/forward cache are reloaded (`pageshow` with `persisted`), so stale
data from an earlier visit is never shown as current.
