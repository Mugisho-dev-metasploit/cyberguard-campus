# Commands

Commands that match this repository. `HOST` stands for the server name.

## Installation

```bash
cp .env.example .env                       # then fill DB_* and SESSION_NAME
cd backend && composer install && cd ..
```

## Stack

```bash
sudo /opt/lampp/lampp start                # Apache + MariaDB
sudo /opt/lampp/lampp stop
sudo /opt/lampp/lampp startapache
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/   # is Apache answering?
```

## Database

```bash
# Apply one migration
/opt/lampp/bin/php -r '
  require "backend/vendor/autoload.php";
  CyberGuard\Campus\Bootstrap\Environment::load(getcwd());
  CyberGuard\Campus\Database\Database::connection()->exec(file_get_contents($argv[1]));' \
  backend/database/migrations/008_create_login_throttle.sql

# Row counts
/opt/lampp/bin/php -r '
  require "backend/vendor/autoload.php";
  CyberGuard\Campus\Bootstrap\Environment::load(getcwd());
  $p = CyberGuard\Campus\Database\Database::connection();
  foreach (["users","devices","events","alerts","incidents","incident_history","audit_logs","login_throttle"] as $t)
    printf("%-18s %d\n", $t, $p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn());'

# Generate a bcrypt hash for a new account
/opt/lampp/bin/php -r 'echo password_hash(readline("password: "), PASSWORD_BCRYPT, ["cost" => 12]), PHP_EOL;'
```

## Tests

```bash
/opt/lampp/bin/php backend/tests/run.php                     # every suite
/opt/lampp/bin/php backend/tests/LoginThrottleTest.php       # one suite
CG_BASE_URL=http://127.0.0.1 /opt/lampp/bin/php backend/tests/WebExposureTest.php
```

## API (with a cookie jar)

```bash
curl -s http://HOST/cyberguard-campus/backend/public/index.php/health
curl -s -c cookies.txt -H 'Content-Type: application/json' \
  -d '{"identifier":"USER","password":"PASSWORD"}' \
  http://HOST/cyberguard-campus/backend/public/index.php/login
curl -s -b cookies.txt http://HOST/cyberguard-campus/backend/public/index.php/api/metrics
curl -s -b cookies.txt -X POST http://HOST/cyberguard-campus/backend/public/index.php/logout
```

## Debugging

```bash
tail -50 /opt/lampp/logs/php_error_log
grep 'index.php' /opt/lampp/logs/access_log | tail -20
/opt/lampp/bin/php -S 127.0.0.1:8099 -t backend/public      # run the API on a private port
/opt/lampp/bin/php -l backend/src/Services/IncidentService.php
```

## Security checks

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://HOST/cyberguard-campus/.env          # expect 403
curl -s -i http://HOST/cyberguard-campus/backend/public/index.php/login | head -3    # expect 405 + Allow: POST
/opt/lampp/bin/php backend/tests/WebExposureTest.php
```

## Git (read-only inspection)

```bash
git status --short
git diff --check
git log --oneline --decorate
```
