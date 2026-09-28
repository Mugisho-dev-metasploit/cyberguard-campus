# API examples

Replace `HOST` with your host. The examples use a cookie jar; no credential is shown.

## Health

```bash
curl -s http://HOST/cyberguard-campus/backend/public/index.php/health
# {"success":true,"application":"CYBERGUARD CAMPUS","status":"healthy"}
```

## Sign in (stores the session cookie)

```bash
curl -s -c cookies.txt \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"YOUR_USERNAME","password":"YOUR_PASSWORD"}' \
  http://HOST/cyberguard-campus/backend/public/index.php/login
```

```json
{
  "success": true,
  "message": "Authentication successful.",
  "user": {
    "uuid": "00000000-0000-4000-8000-000000000000",
    "username": "example.analyst",
    "email": "analyst@example.test",
    "first_name": "Example",
    "last_name": "Analyst",
    "role": "analyst"
  }
}
```

A body sent as `text/plain` or `multipart/form-data` answers `415`, even with valid credentials.

## Read endpoints

```bash
curl -s -b cookies.txt http://HOST/cyberguard-campus/backend/public/index.php/api/metrics
```

```json
{
  "success": true,
  "message": "Metrics retrieved successfully.",
  "data": {"total_events": 0, "total_alerts": 0, "critical_alerts": 0, "open_incidents": 0, "devices": 0}
}
```

```bash
curl -s -b cookies.txt http://HOST/cyberguard-campus/backend/public/index.php/api/incidents
curl -s -b cookies.txt http://HOST/cyberguard-campus/backend/public/index.php/api/incidents/1
```

## Update an incident

```bash
curl -s -b cookies.txt -X PATCH \
  -H 'Content-Type: application/json' \
  -d '{"status":"acknowledged"}' \
  http://HOST/cyberguard-campus/backend/public/index.php/api/incidents/1
```

Refused transition:

```json
{"success": false, "message": "Status cannot change from open to resolved. The next status can only be: acknowledged."}
```

Unknown field:

```json
{"success": false, "message": "Unknown field(s): role"}
```

## Sign out

```bash
curl -s -b cookies.txt -X POST http://HOST/cyberguard-campus/backend/public/index.php/logout
# {"success":true,"message":"Logged out successfully."}
```

## Method not allowed

```bash
curl -s -i http://HOST/cyberguard-campus/backend/public/index.php/login | head -3
# HTTP/1.1 405 Method Not Allowed
# Allow: POST
```
