# Manual testing

Nothing in the repository automates browser testing, so the interface is verified by hand.

## Smoke checklist

| # | Step | Expected |
|---|---|---|
| 1 | `GET /cyberguard-campus/frontend/welcome.html` | Page loads, presentation runs, pause works |
| 2 | Sign in with a valid account | Dashboard with counters |
| 3 | Sign in with a wrong password | "Sign-in failed…", password cleared, no navigation |
| 4 | Six wrong attempts in a row | Sixth shows "Too many sign-in attempts" (429 from the API) |
| 5 | Open Events, Alerts, Devices | Tables or explicit empty states, no console error |
| 6 | Open Incidents, select one | Detail panel with history |
| 7 | As `analyst`, advance an incident | New status and history entry; closing is refused |
| 8 | As `admin`, close a resolved incident | Accepted, `closed_at` set |
| 9 | Open Monitoring | Derived views render; "View as table" shows the same values |
| 10 | Sign out | Returns to Welcome; going back in history does not show data |
| 11 | With the session ended, open a protected page | Redirected to Welcome |
| 12 | Narrow the window below 920 px | Sidebar becomes a drawer; Escape closes it; focus returns to the toggle |

## Verification after a deployment

```bash
curl -s http://HOST/cyberguard-campus/backend/public/index.php/health
curl -s -o /dev/null -w '%{http_code}\n' http://HOST/cyberguard-campus/backend/public/index.php/api/metrics   # expect 401
curl -s -o /dev/null -w '%{http_code}\n' http://HOST/cyberguard-campus/.env                                   # expect 403
/opt/lampp/bin/php backend/tests/run.php
```

## Recording results

There is no test report format in the repository. When a manual pass matters (before a
demonstration, after a deployment), record the date, the commit and the checklist outcome in the
pull request or the changelog entry.
