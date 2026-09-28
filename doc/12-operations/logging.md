# Logging (operations view)

The security reading of the same subject is in
[../06-security/logging-auditing.md](../06-security/logging-auditing.md).

## Where things are written

| Log | Path | Written by | Contents |
|---|---|---|---|
| Access log | `/opt/lampp/logs/access_log` | Apache | Address, request line, status, size — no bodies |
| Error log | `/opt/lampp/logs/error_log` | Apache | Server-level errors |
| PHP log | `/opt/lampp/logs/php_error_log` | PHP | Warnings, fatals, uncaught exceptions with stack traces |
| Audit trail | `audit_logs` table | The application | Authentication events |
| Incident history | `incident_history` table | The application | Who changed what on an incident |

The application writes **no file log of its own**.

## Handling

| Concern | Today | Recommendation |
|---|---|---|
| Rotation | None configured for the PHP log | `logrotate`, weekly, compressed |
| Permissions | `php_error_log` is world-readable (0644, owner `daemon`) | 0640 with an administrators group |
| Historical content | Contains traces written before the redaction work | Rotate, then securely delete the old files |
| Web exposure | Verified: the log directory is outside the document root and no alias points to it (404) | Keep it that way |
| Retention | Undefined | Define with the audit retention policy |

## Useful queries

```sql
-- Recent authentication events
SELECT created_at, action, user_id, ip_address, success
FROM audit_logs WHERE action LIKE 'auth.%' ORDER BY id DESC LIMIT 50;

-- Failures per address over a day
SELECT ip_address, COUNT(*) FROM audit_logs
WHERE action = 'auth.login.failure' AND created_at > NOW() - INTERVAL 1 DAY
GROUP BY ip_address ORDER BY 2 DESC;
```

```bash
# Requests to the API, by status
grep 'index.php' /opt/lampp/logs/access_log | awk '{print $9}' | sort | uniq -c | sort -rn
```
