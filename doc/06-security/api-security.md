# API security

## Transport-level controls on the API

| Control | Detail | Status |
|---|---|---|
| Session authentication | Cookie-based, `HttpOnly`, `SameSite=Lax` | `IMPLEMENTED` |
| Content type on sign-in | `application/json` required; a cross-site HTML form cannot send it without a CORS preflight, which the API never grants | `IMPLEMENTED` |
| Origin check on sign-in | A foreign or opaque `Origin` is refused with 403 | `IMPLEMENTED` |
| Method handling | A known path with another method answers 405 with `Allow` | `IMPLEMENTED` |
| Cache directives | `Cache-Control: no-store`, `Pragma: no-cache` on sign-in and sign-out | `IMPLEMENTED` |
| Version headers | `X-Powered-By` removed by the application | `IMPLEMENTED` |
| CORS | No CORS headers at all: only same-origin browsers can read responses | `IMPLEMENTED` (by absence) |
| Input bounds | Sign-in: 254 characters / 1024 bytes. Incident update: allow-listed fields with typed validation | `IMPLEMENTED` |
| Rate limiting | Sign-in only | `PARTIAL` |
| CSRF on `PATCH /api/incidents/{id}` | `SameSite=Lax` prevents the cookie from being sent on a cross-site request; the JSON body also requires a preflight | `PARTIAL` — no token |
| Security headers (CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy) | None | `PLANNED` |
| Request size limits | PHP's `post_max_size` (40 MB) only | `PARTIAL` |

## Injection surfaces

| Surface | Handling |
|---|---|
| Path parameters | `{id}` validated as a positive integer before any query; otherwise 400 |
| JSON body | Decoded, then field-by-field validation with an allow-list |
| Identifier at sign-in | Bound, trimmed, sent only as a prepared-statement parameter |
| User agent (audit) | Scrubbed to valid UTF-8, control characters removed, truncated to 255 characters, stored as a parameter |

No dynamic SQL is built from user input anywhere; the only dynamic SQL fragment is the `SET`
clause of the incident update, assembled from a fixed allow-list of column names.

## Known gaps

- **No API versioning**: a breaking change would break every client at once.
- **No per-endpoint rate limit**: an authenticated user can poll any list endpoint as fast as
  the server answers.
- **Full-table responses**: an authenticated user can pull every record in one request, which is
  both a performance and a data-exfiltration consideration (`PLANNED`).
