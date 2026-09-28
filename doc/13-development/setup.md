# Developer setup

Installation is the same as running the project; see
[../10-deployment/development.md](../10-deployment/development.md) for the full sequence.

## Minimum to be productive

```bash
cd /opt/lampp/htdocs/cyberguard-campus
cp .env.example .env            # then fill DB_* and SESSION_NAME
cd backend && composer install && cd ..
sudo /opt/lampp/lampp start
/opt/lampp/bin/php backend/tests/run.php
```

Always use the XAMPP PHP binary (`/opt/lampp/bin/php`): the system PHP may lack `pdo_mysql` or
`pdo_sqlite`, which the suites need.

## Editing loop

| Change | What to do |
|---|---|
| PHP | Save; the next request picks it up (no build, no cache) |
| Frontend | Save; reload the page (no bundler) |
| Schema | Add a migration and apply it by hand |
| Route | Edit the route table in `backend/public/index.php` |

## Before proposing a change

```bash
/opt/lampp/bin/php -l <file>              # syntax
/opt/lampp/bin/php backend/tests/run.php  # full suite, must stay green
git diff --check                          # whitespace
```

There is no linter, formatter or static analyser configured in the repository.

## Useful facts

- No test account exists in the repository, by design; create one locally with a random
  password (`10-deployment/development.md`).
- The suites create and delete their own data, and fail if the database is not identical before
  and after.
- `APP_ENV=production` makes the suites refuse to run; keep it out of your development `.env`.
