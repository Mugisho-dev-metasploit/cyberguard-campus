<?php

declare(strict_types=1);

/**
 * Shared support for the versioned backend tests (APP-06.8). No framework, no dependency:
 * each *Test.php file is a standalone script that exits 0 when every check passes.
 *
 * These tests use the configured MariaDB database. Every row they create carries a per-run
 * marker and is deleted at the end, after every secondary connection is closed; row counts of
 * the seven business tables are compared before/after. They refuse to run when APP_ENV is
 * "production".
 */

use CyberGuard\Campus\Bootstrap\Environment;
use CyberGuard\Campus\Database\Database;

// Developer/CI tool only: never runs under a web SAPI (apache2handler, cgi, fpm, cli-server).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__, 3));

// Either source may say production: the .env file (loaded above) or the process environment.
if (($_ENV['APP_ENV'] ?? '') === 'production' || getenv('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing to run: these tests write (then delete) data and APP_ENV is production.\n");
    exit(2);
}

const TK_TABLES = ['incidents', 'incident_history', 'audit_logs', 'users', 'alerts', 'devices', 'events'];
const TK_STATUSES = ['open', 'acknowledged', 'investigating', 'contained', 'resolved', 'closed'];
const TK_NEXT = ['open' => 'acknowledged', 'acknowledged' => 'investigating', 'investigating' => 'contained', 'contained' => 'resolved', 'resolved' => 'closed'];
const TK_STAMP = ['acknowledged' => 'acknowledged_at', 'contained' => 'contained_at', 'resolved' => 'resolved_at', 'closed' => 'closed_at'];
const TK_LIFECYCLE = ['acknowledged_at', 'contained_at', 'resolved_at', 'closed_at'];

/** Per-run marker: all test rows carry it, so cleanup never touches other data. */
function tk_marker(): string
{
    static $marker = null;

    return $marker ??= 'tk' . bin2hex(random_bytes(4));
}

function tk_pdo(): PDO
{
    return Database::connection();
}

/** A second, independent connection (optionally a PDO subclass used as a test double). */
function tk_connect(string $class = PDO::class): PDO
{
    return new $class(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE']),
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
}

/**
 * Sign-in throttling buckets (APP-07.4.1) present before this run: cleanup removes only the
 * buckets created by the run's own sign-in attempts. Buckets idle for 2 h may be purged by the
 * application at any time, so only those active in the hour before the run are counted.
 */
$GLOBALS['tk_throttle_since'] = gmdate('Y-m-d H:i:s', time() - 3600);
$GLOBALS['tk_throttle_before'] = tk_pdo()->query('SELECT throttle_key FROM login_throttle')->fetchAll(PDO::FETCH_COLUMN);

/** @return array<string, int> */
function tk_counts(): array
{
    $counts = [];

    foreach (TK_TABLES as $table) {
        $counts[$table] = (int) tk_pdo()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    $recent = tk_pdo()->prepare('SELECT COUNT(*) FROM login_throttle WHERE last_attempt_at >= :since');
    $recent->execute(['since' => $GLOBALS['tk_throttle_since']]);
    $counts['login_throttle'] = (int) $recent->fetchColumn();

    return $counts;
}

function tk_uuid(): string
{
    return sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
}

function tk_user(string $role, string $first = 'Test'): int
{
    $tag = bin2hex(random_bytes(3));
    tk_pdo()->prepare('INSERT INTO users (uuid, username, email, password_hash, first_name, last_name, role, status)
        VALUES (:uuid, :username, :email, :hash, :first, \'Tester\', :role, \'active\')')->execute([
        'uuid' => tk_uuid(),
        'username' => tk_marker() . "_{$role}_{$tag}",
        'email' => tk_marker() . "_{$tag}@example.test",
        'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'first' => $first,
        'role' => $role,
    ]);

    return (int) tk_pdo()->lastInsertId();
}

/** @param array<string, mixed> $extra */
function tk_incident(string $status, array $extra = []): int
{
    tk_pdo()->prepare('INSERT INTO incidents (incident_uuid, incident_number, alert_id, device_id, title, description, severity, status, priority,
            assigned_to, metadata, detected_at, created_at, updated_at)
        VALUES (:uuid, :number, :alert, :device, :title, \'original description\', 2, :status, \'medium\', :assigned, :metadata,
            \'2026-09-21 08:00:00.000000\', \'2026-09-21 08:00:00.000000\', \'2026-09-21 08:00:00.000000\')')->execute([
        'uuid' => tk_uuid(),
        'number' => tk_marker() . '-' . bin2hex(random_bytes(3)),
        'alert' => $extra['alert_id'] ?? null,
        'device' => $extra['device_id'] ?? null,
        'title' => $extra['title'] ?? 'Versioned test incident',
        'status' => $status,
        'assigned' => $extra['assigned_to'] ?? null,
        'metadata' => $extra['metadata'] ?? null,
    ]);

    return (int) tk_pdo()->lastInsertId();
}

/** Every stored field a change could touch. */
function tk_row(int $id): array
{
    $s = tk_pdo()->prepare('SELECT status, title, description, severity, priority, assigned_to, resolution,
        acknowledged_at, contained_at, resolved_at, closed_at, created_at, updated_at FROM incidents WHERE id = :id');
    $s->execute(['id' => $id]);

    return $s->fetch() ?: [];
}

function tk_history(int $id): array
{
    $s = tk_pdo()->prepare('SELECT id, user_id, action, previous_status, new_status, previous_assignee, new_assignee, comment, created_at
        FROM incident_history WHERE incident_id = :id ORDER BY created_at, id');
    $s->execute(['id' => $id]);

    return $s->fetchAll();
}

/** @return list<string> lifecycle columns that are set */
function tk_stamped(array $row): array
{
    return array_values(array_filter(TK_LIFECYCLE, static fn (string $c): bool => $row[$c] !== null));
}

/** Deletes every row created by this run (history cascades with its incident). */
function tk_cleanup(): void
{
    $pdo = tk_pdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $marker = tk_marker() . '%';
    $pdo->prepare('DELETE FROM incidents WHERE incident_number LIKE :m')->execute(['m' => $marker]);
    $pdo->prepare('DELETE FROM alerts WHERE title LIKE :m')->execute(['m' => $marker]);
    $pdo->prepare('DELETE FROM devices WHERE hostname LIKE :m')->execute(['m' => $marker]);
    $pdo->prepare('DELETE FROM users WHERE username LIKE :m')->execute(['m' => $marker]);

    $created = array_diff($pdo->query('SELECT throttle_key FROM login_throttle')->fetchAll(PDO::FETCH_COLUMN), $GLOBALS['tk_throttle_before']);

    if ($created !== []) {
        $pdo->prepare('DELETE FROM login_throttle WHERE throttle_key IN (' . implode(', ', array_fill(0, count($created), '?')) . ')')
            ->execute(array_values($created));
    }
}

/** Test double: a real connection that throws a chosen exception for statements containing a needle. */
final class TkThrowingPdo extends PDO
{
    public string $failOn = '';
    /** @var (Closure(): Throwable)|null */
    public ?Closure $throw = null;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failOn !== '' && str_contains($query, $this->failOn)) {
            throw $this->throw !== null ? ($this->throw)() : new PDOException('Simulated failure (test double)');
        }

        return parent::prepare($query, $options);
    }
}

/** Test double: records every prepared statement and transaction call (read-only checks). */
final class TkRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];
    public int $transactions = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }

    public function beginTransaction(): bool
    {
        $this->transactions++;

        return parent::beginTransaction();
    }
}

/* ---- Reporting ------------------------------------------------------------------------ */

$GLOBALS['tk_results'] = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['tk_results'][] = [$name, $ok, $detail];
}

/** Prints the results and exits; also fails if the database is not back to `$before`. */
function tk_finish(array $before): never
{
    $after = tk_counts();
    check('database back to its initial state (' . implode(', ', TK_TABLES) . ', login_throttle)', $after === $before,
        json_encode(['before' => $before, 'after' => $after]));

    $failed = 0;

    foreach ($GLOBALS['tk_results'] as [$name, $ok, $detail]) {
        $failed += $ok ? 0 : 1;
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . (!$ok && $detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    }

    $total = count($GLOBALS['tk_results']);
    echo PHP_EOL . ($total - $failed) . "/$total checks passed" . PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}
