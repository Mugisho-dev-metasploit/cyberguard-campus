# Components

There is no component framework: shared behaviour lives in modules that build DOM nodes.

## Shell (`shell.js`)

Renders, into `#app-shell`, on every protected page:

- skip link, topbar (menu toggle, brand, breadcrumb);
- sidebar with the navigation groups **Operations** (Dashboard, Events, Alerts, Incidents) and
  **Infrastructure** (Devices, Monitoring);
- sidebar footer with the product name and the **Sign out** button and its status line.

A navigation item without an `href` renders as unavailable ("Soon"), which is how a page is
kept out of reach until it exists.

## Navigation (`navigation.js`)

Mobile drawer only: open/close, overlay, `Escape`, focus management, scroll lock, and `inert`
on the background so the keyboard cannot reach it while the drawer is open.

## Formatting and building blocks (`format.js`)

| Helper | Use |
|---|---|
| `el(tag, options, children)` | The only element factory; text is set with `textContent` |
| `icon(name)` | SVG sprite reference |
| `formatDateTime`, `formatRelativeTime`, `formatClockTime` | Dates from the API |
| `formatCount`, `toCount` | Numbers |
| `severityTag`, `statusTag`, `incidentStatusTag`, `deviceStatusTag` | Coloured labels |
| `stateBlock({variant, title, message, action})` | Loading, empty and error states |
| `describeError(error, subject)` | User-facing copy for an `ApiError` |

## Charts (`charts.js`)

Built with `createElement`/`createElementNS`, no library and no HTML strings. Every chart has a
hover and keyboard tooltip and a "View as table" twin. Colours come from the design tokens of
the dark theme; a single-series chart carries no legend.

## Sign-out button

Declared by the shell, wired in `app.js`: one request at a time, the button is disabled and
labelled "Signing out…" while it runs, a failure shows a generic message and re-enables it, and
the visit ends only once the backend confirmed.
