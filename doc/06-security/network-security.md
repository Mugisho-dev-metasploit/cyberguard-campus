# Network security (application exposure)

## Web exposure policy

The repository lives inside the Apache document root, so the default would be to serve
everything. Three `.htaccess` files change that:

| File | Effect |
|---|---|
| `.htaccess` (project root) | `Require all denied`, `Options -Indexes`, dotfiles answered 404 |
| `frontend/.htaccess` | `Require all granted` — the interface |
| `backend/public/.htaccess` | `Require all granted` — the API front controller |

Everything else — `.env`, `.git`, `backend/src`, `backend/tests`, `backend/database`,
`backend/vendor`, Composer files, `docs`, `infrastructure`, `scripts` — answers 403. Verified
by `WebExposureTest` over HTTP, including over HTTPS.

This depends on `AllowOverride All` for the document root (the XAMPP default). The equivalent
server-level configuration is documented in `backend/tests/README.md`.

## Transport

| Property | Today | Target |
|---|---|---|
| Listener | `*:80` (HTTP) and `*:443` (HTTPS, same document root) | HTTPS only, HTTP redirecting |
| Cookie `Secure` | Off (not production) | On, with `APP_ENV=production` |
| HSTS | None | `Strict-Transport-Security` from the web server |
| Certificates | XAMPP default | Managed certificate |

In the laboratory, credentials and session cookies travel in clear on the network. That is the
single largest residual risk in the current deployment (`PLANNED`, infrastructure).

## Session storage

PHP sessions are files in `/opt/lampp/temp`, a world-writable directory shared with every other
application of the XAMPP host, all running as the same user. Consequences:

- another application on the host can read or write CYBERGUARD sessions;
- its garbage collector (24 minutes) can delete sessions before their 6-hour lifetime.

A different directory would not fix this, since the user is the same: the fix is a dedicated
PHP-FPM pool and user (`PLANNED`, infrastructure).

## Ports

See [16-reference/ports.md](../16-reference/ports.md).

## Version disclosure

`X-Powered-By` is removed by the application. `Server:` still advertises Apache, OpenSSL, PHP
and mod_perl versions; that is `ServerTokens Full` in the XAMPP configuration, shared by every
site on the host (`PLANNED`, infrastructure).
