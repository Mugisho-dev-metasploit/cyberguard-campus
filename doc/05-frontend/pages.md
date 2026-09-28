# Pages

Each page declares its identity with `<body data-page="…">`, loads `app.js` (except the public
pages) and its own module.

| Page | File | Public | Module | API calls |
|---|---|:--:|---|---|
| Welcome | `welcome.html` | yes | `pages/welcome.js` | none |
| Sign in | `login.html` | yes | `pages/login.js` | `POST /login` |
| Dashboard | `index.html` | no | `pages/dashboard.js` | `GET /api/metrics`, `GET /api/alerts` |
| Events | `events.html` | no | `pages/events.js` | `GET /api/events` |
| Alerts | `alerts.html` | no | `pages/alerts.js` | `GET /api/alerts`, `GET /api/metrics` |
| Incidents | `incidents.html` | no | `pages/incidents.js` | `GET /api/incidents`, `GET /api/incidents/{id}`, `PATCH /api/incidents/{id}` |
| Devices | `devices.html` | no | `pages/devices.js` | `GET /api/devices` |
| Monitoring | `monitoring.html` | no | `pages/monitoring.js` + `pages/monitoring-data.js` | `GET /api/metrics`, `/api/events`, `/api/alerts`, `/api/devices` |

## Page notes

**Welcome** — a five-chapter presentation driven by one self-rescheduling timeout; pauses on
request and when the tab is hidden; no network call, no storage. It links to sign-in.

**Sign in** — one form, `method="post"` so a submission without JavaScript never puts
credentials in the URL. Blocks double submission, clears the password after a failure, shows
generic messages only, and maps 429 to "Too many sign-in attempts".

**Dashboard** — five counters from `/api/metrics`, plus alert activity for the last 14 days
built from `/api/alerts`.

**Events / Alerts / Devices** — tables built from the matching endpoint, with severity and
status tags, relative times, and explicit empty states. Devices shows the recorded status
against the age of the last observation.

**Incidents** — list plus a detail view with the history; the available actions depend on the
role: a `viewer` sees read-only, an `analyst` can move the incident forward, an `admin` can
close it. Refused transitions show the message returned by the API.

**Monitoring** — loads four endpoints, then derives counts per day, per hour and per
environment in `monitoring-data.js` (pure functions, no DOM, no network).
