# Frontend overview

A static interface: HTML pages, ES modules and CSS served directly by Apache. No framework, no
bundler, no `package.json`, no build step — the files in `frontend/` are the files the browser
receives.

| Item | Value |
|---|---|
| Pages | 8 HTML files |
| Modules | 16 ES modules (`type="module"`) |
| Styles | 4 shared stylesheets + 8 page stylesheets |
| Fonts | IBM Plex Sans / Mono, self-hosted (`woff2`, SIL OFL licence included) |
| Images | 4 files (branding) |
| Dependencies | None |
| API calls | `fetch` with `credentials: 'same-origin'` |

## Structure

```text
frontend/
├── welcome.html  login.html  index.html
├── events.html   alerts.html incidents.html
├── devices.html  monitoring.html
├── .htaccess                     re-allows web access to this directory
└── assets/
    ├── css/    app.css, layout.css, components.css, navigation.css, pages/*.css
    ├── fonts/  IBM Plex woff2 + OFL.txt
    ├── img/    branding
    └── js/
        ├── api.js         the only HTTP client
        ├── auth.js        sign-in, sign-out, reaction to 401
        ├── app.js         bootstrap of protected pages
        ├── shell.js       sidebar, topbar, navigation items
        ├── navigation.js  mobile drawer behaviour
        ├── format.js      dates, numbers, tags, DOM helpers
        ├── charts.js      SVG/HTML charts with a table twin
        └── pages/         one module per page
```

## Principles visible in the code

- **The backend decides.** The interface keeps no "signed in" flag; a 401 from a protected
  endpoint sends the visitor back to the public entry.
- **Nothing is stored.** No `localStorage`, no `sessionStorage`, no cookie written by
  JavaScript; the session cookie is `HttpOnly`.
- **No HTML strings.** Every node is built with `createElement` helpers (`format.js`), so user
  or API data is inserted as text.
- **No estimation.** Charts and monitoring views only count and group what the API returned.

## Pages and data

See [pages.md](pages.md) for the page → module → endpoint mapping.
