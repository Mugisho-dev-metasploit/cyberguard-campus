# Migrations (backend view)

Files: `backend/database/migrations/001…008`. Content and columns are described in
[09-database/migrations.md](../09-database/migrations.md) and
[09-database/schema.md](../09-database/schema.md).

## How they are applied

There is **no migration runner** in the repository: no `migrate` command, no tracking table, no
version column. Each file is a plain `CREATE TABLE` statement, applied by hand.

Observed practice (from the last migration added):

```bash
# from the project root, with the application credentials in .env
/opt/lampp/bin/php -r '
require "backend/vendor/autoload.php";
CyberGuard\Campus\Bootstrap\Environment::load(getcwd());
$pdo = CyberGuard\Campus\Database\Database::connection();
$pdo->exec(file_get_contents("backend/database/migrations/008_create_login_throttle.sql"));'
```

The database user has `CREATE`, `ALTER`, `INDEX` and `REFERENCES` on the `cyberguard` schema, so
this works without administrative credentials.

## Consequences

| Consequence | Detail |
|---|---|
| No idempotency | Re-running a file fails with "table already exists" |
| No down migration | Rolling back means dropping the table by hand |
| No record of what is applied | The only check is comparing `information_schema` with the files |
| Order matters | Foreign keys require `001` → `008` in order |

A runner with a `schema_migrations` table is `PLANNED`.
