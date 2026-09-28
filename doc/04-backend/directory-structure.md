# Backend directory structure

```text
backend/
├── composer.json          vlucas/phpdotenv, PSR-4 autoload
├── composer.lock
├── config/                empty (PLANNED)
├── routes/                empty (PLANNED — routes live in public/index.php)
├── database/
│   └── migrations/        001…008, applied by hand
├── public/
│   ├── index.php          front controller: wiring + route table
│   └── .htaccess          re-allows web access to this directory only
├── src/
│   ├── Bootstrap/         Environment
│   ├── Controllers/       6 controllers
│   ├── Core/              Application, HttpRequest, HttpResponse, SessionManager
│   ├── Database/          Database (PDO factory)
│   ├── Exceptions/        AuthenticationException, AuthorizationException
│   ├── Middleware/        AuthenticationMiddleware, AuthorizationMiddleware
│   ├── Models/            User, Device, Event, Alert, Incident
│   ├── Repositories/      8 repositories
│   ├── Routing/           Router
│   └── Services/          9 services
├── tests/                 21 suites + run.php + support/
└── vendor/                Composer dependencies (not served)
```

`backend/config/` and `backend/routes/` exist but are empty: configuration comes from `.env`
and routes are declared in `public/index.php`.

## Files by role

| Role | Files |
|---|---|
| HTTP entry | `public/index.php` |
| Request/response | `Core/HttpRequest.php`, `Core/HttpResponse.php` |
| Routing | `Routing/Router.php` |
| Access control | `Middleware/*.php`, `Core/SessionManager.php` |
| Authentication logic | `Services/AuthenticationService.php`, `Services/LoginThrottle.php`, `Services/AuthenticationAudit.php`, `Services/AuthenticationResult.php` |
| Business logic | `Services/IncidentService.php` and the four read services |
| Data access | `Repositories/*.php`, `Database/Database.php` |
| Schema | `database/migrations/*.sql` |
