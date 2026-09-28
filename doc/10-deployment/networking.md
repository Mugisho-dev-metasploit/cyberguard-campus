# Deployment networking

## Flows the application needs

| Flow | Protocol / port | Required |
|---|---|---|
| Client → web server | TCP 443 (HTTPS target), TCP 80 today | yes |
| Web server → database | TCP 3306, loopback today | yes |
| Application → anywhere else | none | no outbound connection is made |

The application is entirely inbound: it initiates no connection of its own. Any future
integration (notifications, webhooks, sensors) would add outbound or inbound flows that must be
opened explicitly — see [../03-architecture/integration-architecture.md](../03-architecture/integration-architecture.md).

## Listening surface observed

| Service | Bind | Note |
|---|---|---|
| Apache | `*:80` and `*:443` | Serves every application of the host |
| MariaDB | `127.0.0.1:3306` | Not reachable from the network |

## Recommendations for production (`PLANNED`)

- Terminate TLS at a reverse proxy or at Apache; redirect HTTP.
- Keep the database on loopback or on a private network; never expose 3306.
- If a proxy is added, preserve the original `Host`: the sign-in origin check compares the
  browser's `Origin` with `scheme://Host` as PHP sees it, and a rewritten `Host` makes
  legitimate sign-ins fail (it fails closed, never open).
- The application trusts no `X-Forwarded-*` header: behind a proxy, `REMOTE_ADDR` becomes the
  proxy address, and sign-in throttling then groups every client together. Decide explicitly
  before deploying behind a proxy.
