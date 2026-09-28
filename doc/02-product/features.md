# Features

Every line is checked against the code. "Where" points at the implementation.

## Authentication and accounts

| Feature | Where | Status |
|---|---|---|
| Sign in with username or email + password | `LoginController::login`, `AuthenticationService` | `IMPLEMENTED` |
| Server-side sign-out | `LoginController::logout`, `SessionManager::logout` | `IMPLEMENTED` |
| 6-hour absolute session, no sliding renewal | `SessionManager` | `IMPLEMENTED` |
| Session revoked when the account is disabled, locked or deleted | `AuthenticationMiddleware` | `IMPLEMENTED` |
| Role read from the database on every request | `AuthenticationMiddleware::handle` | `IMPLEMENTED` |
| Brute-force throttling with progressive delays | `LoginThrottle` | `IMPLEMENTED` |
| Password hash upgrade on sign-in | `AuthenticationService` | `IMPLEMENTED` |
| Account creation, password change, password reset | — | `PLANNED` |
| Multi-factor authentication | — | `PLANNED` |

## Security operations

| Feature | Where | Status |
|---|---|---|
| List incidents | `GET /api/incidents` | `IMPLEMENTED` |
| Incident detail with history | `GET /api/incidents/{id}` | `IMPLEMENTED` |
| Update an incident (title, description, severity, status, priority, assignee, resolution) | `PATCH /api/incidents/{id}` | `IMPLEMENTED` |
| Status transitions enforced server-side, one step at a time | `IncidentService::STATUS_TRANSITIONS` | `IMPLEMENTED` |
| Closing reserved to administrators | `IncidentService::STATUS_PERMISSIONS` | `IMPLEMENTED` |
| Lifecycle timestamps stamped by the database | `IncidentService::LIFECYCLE_TIMESTAMPS` | `IMPLEMENTED` |
| Incident history (who, what, previous/new value) | `incident_history` | `IMPLEMENTED` |
| List events, alerts, devices | `GET /api/events`, `/api/alerts`, `/api/devices` | `IMPLEMENTED` |
| Summary metrics | `GET /api/metrics` | `IMPLEMENTED` |
| Create an incident from an alert | — | `PLANNED` |
| Assign or comment outside a status change | Assignment yes (`assigned_to`), free comments no | `PARTIAL` |
| Filter, sort or paginate any list | — | `PLANNED` |

## Interface

| Feature | Where | Status |
|---|---|---|
| Welcome page with a short presentation | `frontend/welcome.html` | `IMPLEMENTED` |
| Sign-in page | `frontend/login.html` | `IMPLEMENTED` |
| Dashboard, events, alerts, incidents, devices, monitoring | `frontend/*.html` | `IMPLEMENTED` |
| Charts without a library, with a table twin | `frontend/assets/js/charts.js` | `IMPLEMENTED` |
| Responsive layout, keyboard support, reduced motion | `frontend/assets/css` | `IMPLEMENTED` |
| Sign-out button wired to the API | `frontend/assets/js/app.js` | `IMPLEMENTED` |
| Any create/edit form other than sign-in and incident updates | — | `PLANNED` |

## Platform

| Feature | Where | Status |
|---|---|---|
| Authentication audit trail | `AuthenticationAudit`, `audit_logs` | `IMPLEMENTED` |
| Web exposure restricted to the interface and the API | `.htaccess` files | `IMPLEMENTED` |
| Automated test suites | `backend/tests` | `IMPLEMENTED` |
| Event ingestion, detection, correlation | — | `PLANNED` |
| Notifications, webhooks, automation | — | `PLANNED` |
| Multi-tenancy | — | `PLANNED` |
| Docker packaging, CI | — | `PLANNED` |
