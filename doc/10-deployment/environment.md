# Environment configuration

The application reads its configuration from `.env` through `vlucas/phpdotenv`
(`Environment::load`), in *immutable* mode: a variable already present in the real environment
is not overwritten.

Full reference, with sensitivity and consumers: [16-reference/environment-variables.md](../16-reference/environment-variables.md).

## Reading precedence, in practice

| Variable | Read from | Note |
|---|---|---|
| `DB_*` | `$_ENV`, then `$_SERVER` | A missing one throws at bootstrap |
| `SESSION_NAME` | `$_ENV` | Defaults to `cyberguard_session` |
| `APP_ENV` | `$_ENV`, `$_SERVER`, `getenv()` — production wins if any of them says so | Deliberate: the `Secure` cookie must never be lost because the variable arrived by another path |
| `APP_NAME`, `APP_DEBUG`, `APP_URL` | Present in `.env.example`; **not read by any code today** | `UNKNOWN` purpose beyond documentation |

## Files

| File | Tracked | Contents |
|---|---|---|
| `.env.example` | yes | Placeholder values only |
| `.env` | **no** (`.gitignore`) | Real values, refused over HTTP by `.htaccess` |

## Changing configuration

1. Edit `.env` (or the server environment).
2. No cache to clear; the next request reads the new value.
3. Changing `SESSION_NAME` invalidates every existing session cookie by design.
4. Changing `APP_ENV` to `production` turns on the `Secure` cookie: over plain HTTP, browsers
   will then refuse to send it, and sign-in will appear to fail. Deploy HTTPS first.
