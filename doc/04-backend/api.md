# Backend API layer

This page describes how the API layer is built. The endpoint reference lives in
[08-api/endpoints.md](../08-api/endpoints.md).

## Router (`Routing/Router.php`)

- Registration: `get()`, `post()`, `patch()`, each taking a path, a handler and a list of
  middleware.
- Matching: exact path first, then patterns with `{param}` (one segment, `[^/]+`).
- No route for the method but the path exists under another method → **405** with an `Allow`
  header listing the methods, sorted.
- No route at all → **404** `{"success":false,"message":"Route not found."}`.
- Middleware are composed into a pipeline ending on the handler; each one either calls `$next`
  or answers and returns.

`HEAD` is not treated as `GET`: `HEAD /health` answers 405 with `Allow: GET`.

## Request (`Core/HttpRequest.php`)

Built once in `fromGlobals()`:

| Value | Source | Note |
|---|---|---|
| method | `REQUEST_METHOD` | upper-cased |
| path | `REQUEST_URI` minus `SCRIPT_NAME` | so `/backend/public/index.php/api/events` → `/api/events` |
| body | `php://input` decoded as JSON, else `$_POST` when the raw body is empty | invalid JSON → empty body + `jsonValid() === false` |
| clientAddress | `REMOTE_ADDR` | no `X-Forwarded-*` is trusted |
| contentType | `CONTENT_TYPE` | used by the sign-in JSON check |
| origin / serverOrigin | `HTTP_ORIGIN`, scheme + `HTTP_HOST` | used by the sign-in origin check |
| userAgent | `HTTP_USER_AGENT` | recorded (bounded) in the audit trail |

Accessors: `input($key)`, `body()`, `routeParam($key)`, `jsonValid()`, `isJson()`,
`isSameOrigin()`, `clientAddress()`, `userAgent()`.

## Response (`Core/HttpResponse.php`)

`HttpResponse::json(array $data, int $status = 200, array $headers = [])`:

- sets the status code, `Content-Type: application/json; charset=utf-8`, then any extra headers
  (`Retry-After`, `Allow`, `Cache-Control`, `Pragma`);
- encodes with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`.

## Response shape

```json
{ "success": true, "message": "Incidents retrieved successfully.", "data": [] }
```

Errors keep `success` and `message` and drop `data` (except the list endpoints, which return an
empty `data` on failure). Messages are fixed strings; validation messages from
`IncidentService` are the only ones that vary, and they describe the rule, never internals.
