# Operations troubleshooting

Deployment-time problems are in
[../10-deployment/troubleshooting.md](../10-deployment/troubleshooting.md). This page covers
running-system symptoms.

## A user cannot sign in

| Check | Command or query |
|---|---|
| Is the account active? | `SELECT status, deleted_at FROM users WHERE username = '…';` |
| Is it throttled? | Look for `auth.login.throttled` in `audit_logs` for that period |
| Did the attempt reach the server? | `grep 'index.php/login' /opt/lampp/logs/access_log | tail` |
| Is it a 415 or 403? | The client is not sending JSON, or the `Origin` does not match |

An account is never locked by the application; a delay always expires (15 minutes at most).

## Everyone is signed out unexpectedly

Likely the shared session directory was cleaned by another application on the host, or the
service restarted with a different `SESSION_NAME`. Confirm with the timestamps of the session
files and with `.env`.

## A page shows an error state

The interface only shows generic copy. Find the cause on the server:

```bash
tail -50 /opt/lampp/logs/php_error_log
grep 'index.php/api' /opt/lampp/logs/access_log | tail -20
```

A 500 from a list endpoint is almost always a database problem; a 401 means the session ended.

## The application is slow

Most likely cause given the design: a list endpoint returning a whole table. Check the row
counts, then the response size:

```bash
curl -s -o /dev/null -w '%{size_download} bytes in %{time_total}s\n' \
  -b cookies.txt http://HOST/cyberguard-campus/backend/public/index.php/api/events
```

Pagination is the fix (`PLANNED`), not more hardware.

## Suspicious sign-in activity

```sql
SELECT ip_address, COUNT(*) AS failures
FROM audit_logs
WHERE action = 'auth.login.failure' AND created_at > NOW() - INTERVAL 1 HOUR
GROUP BY ip_address ORDER BY failures DESC;
```

Containment options are listed in
[../06-security/incident-response.md](../06-security/incident-response.md).
