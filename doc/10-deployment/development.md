# Development setup

What the repository actually needs to run. There is no installer and no container: the project
is served directly by a local Apache + PHP + MariaDB stack (XAMPP on the reference machine).

## Prerequisites

| Requirement | Reference version | Note |
|---|---|---|
| Apache with `mod_php` | 2.4.58 (XAMPP) | `AllowOverride All` on the document root, so the `.htaccess` rules apply |
| PHP | 8.2.12 | Extensions: `pdo_mysql`, `pdo_sqlite` (tests), `mbstring`, `curl` (tests) |
| MariaDB or MySQL | 11.8.6 | Local instance, loopback only |
| Composer | any recent | Only to install one dependency |
| Git | any recent | |

## Steps

```bash
# 1. Place the project inside the Apache document root
cd /opt/lampp/htdocs
git clone <repository-url> cyberguard-campus
cd cyberguard-campus

# 2. Configure the environment
cp .env.example .env
#    then edit .env: DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL, SESSION_NAME

# 3. Install the backend dependency
cd backend && composer install && cd ..

# 4. Create the database and its user (adapt to your instance)
#    CREATE DATABASE cyberguard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#    CREATE USER 'cyberguard'@'localhost' IDENTIFIED BY '<your password>';
#    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER
#      ON cyberguard.* TO 'cyberguard'@'localhost';

# 5. Apply the migrations, in order
for f in backend/database/migrations/0*.sql; do
  /opt/lampp/bin/php -r '
    require "backend/vendor/autoload.php";
    CyberGuard\Campus\Bootstrap\Environment::load(getcwd());
    CyberGuard\Campus\Database\Database::connection()->exec(file_get_contents($argv[1]));' "$f"
done

# 6. Create a first account (no interface exists for this)
#    INSERT INTO users (uuid, username, email, password_hash, first_name, last_name, role, status)
#    VALUES (UUID(), 'your.name', 'you@example.test', '<bcrypt hash>', 'Your', 'Name', 'admin', 'active');
#    Generate the hash with:
/opt/lampp/bin/php -r 'echo password_hash(readline("password: "), PASSWORD_BCRYPT, ["cost" => 12]), PHP_EOL;'

# 7. Start the stack and verify
sudo /opt/lampp/lampp start
curl -s http://localhost/cyberguard-campus/backend/public/index.php/health
```

## URLs

| Purpose | URL |
|---|---|
| Interface | `http://localhost/cyberguard-campus/frontend/welcome.html` |
| API | `http://localhost/cyberguard-campus/backend/public/index.php/...` |

## Verification

```bash
/opt/lampp/bin/php backend/tests/run.php     # needs Apache and MariaDB running
```

A run must end with every suite passing and the database counters identical before and after.
