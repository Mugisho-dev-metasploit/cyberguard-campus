<?php

declare(strict_types=1);

/**
 * APP-07.4.1 — sign-in throttling (anti brute-force).
 *
 * Part A, in process: the real LoginThrottle + LoginThrottleRepository on the configured
 * database, with an injected clock (windows and delays checked without waiting), and
 * documentation addresses (198.51.100.0/24) as client sources.
 * Part B, over HTTP: the real front controller on a private PHP server with 10 workers
 * (genuinely concurrent requests), a private session store, temporary users whose password is
 * random for this run. Every bucket and user created here is removed at the end.
 *
 * Run: /opt/lampp/bin/php backend/tests/LoginThrottleTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-throttle-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
$server = null;
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';

const TOO_MANY = ['success' => false, 'message' => 'Too many sign-in attempts. Try again later.'];
const INVALID = ['success' => false, 'message' => 'Invalid credentials.'];

$repository = new LoginThrottleRepository(tk_pdo());
$now = microtime(true);
$throttle = new LoginThrottle($repository, static function () use (&$now): float {
    return $now;
});

/** @return array<string, mixed>|null the stored bucket row for an identifier/source */
function bucket(string $scope, string $identifier, string $source): ?array
{
    global $repository;
    $key = $repository->keys(trim($identifier), $source)[$scope];
    $s = tk_pdo()->prepare('SELECT * FROM login_throttle WHERE throttle_key = :k');
    $s->execute(['k' => $key]);

    return $s->fetch() ?: null;
}

/** Sets both buckets of an identifier/source as if their delay had elapsed. */
function expire_buckets(string $identifier, string $source): void
{
    global $repository;
    $keys = array_values($repository->keys(trim($identifier), $source));
    tk_pdo()->prepare('UPDATE login_throttle SET blocked_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE throttle_key IN (?, ?)')->execute($keys);
}

/**
 * Parallel POST /login requests (curl multi).
 * @param list<array<string, mixed>> $bodies
 * @return list<array{status: int, json: mixed, headers: array<string, string>, cookies: list<string>}>
 */
function login_many(array $bodies): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($bodies as $i => $body) {
        $h = curl_init($GLOBALS['base'] . '/login');
        $headers[$i] = [];
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, $i): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[$i][] = [strtolower(trim($name)), trim($value)];
                }

                return strlen($line);
            }]);
        curl_multi_add_handle($multi, $h);
        $handles[$i] = $h;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $h) {
        $flat = [];
        $cookies = [];
        foreach ($headers[$i] as [$name, $value]) {
            $name === 'set-cookie' ? $cookies[] = $value : $flat[$name] = $value;
        }
        $out[] = ['status' => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'json' => json_decode((string) curl_multi_getcontent($h), true),
            'headers' => $flat, 'cookies' => $cookies];
        curl_multi_remove_handle($multi, $h);
        curl_close($h);
    }
    curl_multi_close($multi);

    return $out;
}

function login(string $identifier, string $password): array
{
    return login_many([['identifier' => $identifier, 'password' => $password]])[0];
}

function api_status(string $path, string $sid): int
{
    global $cookieName;
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "Cookie: $cookieName=$sid\r\n", 'ignore_errors' => true, 'timeout' => 15]]);
    @file_get_contents($GLOBALS['base'] . $path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return (int) ($m[1] ?? 0);
}

try {
    $m = tk_marker();

    /* A. Policy, in process ---------------------------------------------------------------------- */

    // T1 — first attempt allowed.
    check('T1 first attempt → allowed', $throttle->attempt("{$m}_t1", '198.51.100.1') === null);

    // T2 — repeated failures: 5 free attempts from one source, then 30 s, 60 s, 120 s…
    $id = "{$m}_t2";
    $free = [];
    for ($i = 1; $i <= 4; $i++) {
        $free[] = $throttle->attempt($id, '198.51.100.2');
    }
    $fifth = $throttle->attempt($id, '198.51.100.2');
    $waits = [$throttle->attempt($id, '198.51.100.2')];
    $now += 30;
    $sixth = $throttle->attempt($id, '198.51.100.2');
    $waits[] = $throttle->attempt($id, '198.51.100.2');
    $now += 60;
    $seventh = $throttle->attempt($id, '198.51.100.2');
    $waits[] = $throttle->attempt($id, '198.51.100.2');
    check('T2 attempts 1–5 from one source → allowed', $free === [null, null, null, null] && $fifth === null);
    check('T2 progressive delay after the free attempts: 30 s, then 60 s, then 120 s', $waits === [30, 60, 120] && $sixth === null && $seventh === null,
        json_encode($waits));
    check('T2 delay is capped at 15 minutes', LoginThrottle::delay(0) === 30 && LoginThrottle::delay(5) === 900 && LoginThrottle::delay(60) === 900);

    // T3 — beyond the threshold: refused, not counted, the wait is not extended.
    $row = bucket('source_account', $id, '198.51.100.2');
    $now += 10;
    $refused = [$throttle->attempt($id, '198.51.100.2'), $throttle->attempt($id, '198.51.100.2')];
    check('T3 attempts during the delay → refused with the remaining wait (110 s), not counted, delay not extended',
        $refused === [110, 110] && bucket('source_account', $id, '198.51.100.2') == $row, json_encode($refused));

    // T4 — window: after 1 hour without attempts the bucket starts again from zero.
    $now += 3600;
    $afterIdle = [];
    for ($i = 1; $i <= 5; $i++) {
        $afterIdle[] = $throttle->attempt($id, '198.51.100.2');
    }
    check('T4 after 1 h idle: counter reset, 5 free attempts again', $afterIdle === [null, null, null, null, null]
        && (int) bucket('source_account', $id, '198.51.100.2')['attempts'] === 5, json_encode($afterIdle));

    // T5 — shared address (campus NAT): A failing does not slow B down.
    $now += 10;
    for ($i = 1; $i <= 7; $i++) {
        $throttle->attempt("{$m}_t5a", '198.51.100.5');
    }
    $aBlocked = $throttle->attempt("{$m}_t5a", '198.51.100.5');
    $b = [];
    for ($i = 1; $i <= 4; $i++) {
        $b[] = $throttle->attempt("{$m}_t5b", '198.51.100.5');
    }
    check('T5 same address: A is throttled, B (another identifier) is not', $aBlocked !== null && $b === [null, null, null, null]);

    // T6 — same account from other addresses: the account bucket still applies.
    $id6 = "{$m}_t6";
    $spread = [];
    for ($i = 1; $i <= 10; $i++) {
        $spread[] = $throttle->attempt($id6, "198.51.100.1$i");   // one attempt per address, 10 addresses
    }
    $fresh = $throttle->attempt($id6, '198.51.100.99');
    check('T6 10 attempts from 10 addresses, then a new address → throttled by the account bucket', !in_array(1, array_map('is_int', $spread), true)
        && is_int($fresh) && $fresh === 30, json_encode([$spread, $fresh]));
    $variants = ["{$m}_T7V", strtoupper("{$m}_t7v"), "  {$m}_t7v  "];
    $now += 1;
    foreach (range(1, 10) as $i) {
        $throttle->attempt($variants[$i % 3], "198.51.100.1$i");
    }
    check('T6 case / surrounding-space variants of one identifier share the account bucket', is_int($throttle->attempt("{$m}_t7v", '198.51.100.98')));
    $accent = [];
    foreach (range(1, 10) as $i) {
        $accent[] = $throttle->attempt($i % 2 ? "{$m}_éclair" : strtoupper("{$m}_ECLAIR"), "198.51.100.3$i");
    }
    check('T6 accent variants (as matched by MariaDB utf8mb4_unicode_ci) share the account bucket',
        is_int($throttle->attempt("{$m}_eclair", '198.51.100.97')));

    // T11 — nothing identifying is stored.
    $raw = json_encode(tk_pdo()->query('SELECT * FROM login_throttle')->fetchAll());
    $columns = tk_pdo()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_throttle' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    check('T11 table holds only key digest, scope, counter and timestamps', $columns === ['throttle_key', 'scope', 'attempts', 'last_attempt_at', 'blocked_until']);
    check('T11 no identifier, address or marker in stored rows (only SHA-256 digests)', !str_contains($raw, $m) && !str_contains($raw, '198.51.100')
        && preg_match_all('/"throttle_key":"[0-9a-f]{64}"/', $raw) === count(tk_pdo()->query('SELECT 1 FROM login_throttle')->fetchAll()));

    /* B. Over HTTP, real front controller ---------------------------------------------------------- */

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $GLOBALS['base'] = "http://127.0.0.1:$port/index.php";
    $server = proc_open([PHP_BINARY, '-d', "session.save_path=$store", '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, getenv() + ['PHP_CLI_SERVER_WORKERS' => '10']);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $password = bin2hex(random_bytes(16));   // random for this run, stored only as a hash
    $user = tk_user('analyst');
    $user2 = tk_user('viewer');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id IN (:a, :b)')->execute(['h' => $hash, 'a' => $user, 'b' => $user2]);
    $names = tk_pdo()->query("SELECT id, username FROM users WHERE id IN ($user, $user2)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $existing = $names[$user];
    $unknown = "{$m}_nobody";

    // T7 / T8 — unknown and existing (active) identifiers: identical sequence and responses.
    $seq = ['unknown' => [], 'existing' => []];
    foreach (['unknown' => $unknown, 'existing' => $existing] as $label => $ident) {
        for ($i = 1; $i <= 6; $i++) {
            $seq[$label][] = login($ident, 'wrong-' . bin2hex(random_bytes(6)));
        }
    }
    $shape = static fn (array $r): array => [$r['status'], $r['json'], $r['cookies'], isset($r['headers']['retry-after'])];
    check('T7/T8 unknown and existing identifier: 5 × 401 then 429, same bodies, headers and no cookie',
        array_map($shape, $seq['unknown']) === array_map($shape, $seq['existing'])
        && array_column($seq['existing'], 'status') === [401, 401, 401, 401, 401, 429], json_encode(array_column($seq['existing'], 'status')));
    $r429 = $seq['existing'][5];
    $retry = (int) ($r429['headers']['retry-after'] ?? 0);
    check('T3 429: generic body, Retry-After in 1..30 s, JSON content type, no session cookie', $r429['json'] === TOO_MANY
        && $retry >= 1 && $retry <= 30 && (string) $retry === ($r429['headers']['retry-after'] ?? '')
        && str_starts_with($r429['headers']['content-type'] ?? '', 'application/json') && $r429['cookies'] === []);
    check('T7 429 body reveals no count, key, address or timestamp', count($r429['json']) === 2
        && !preg_match('/\d|127\.0\.0\.1|[0-9a-f]{64}/', json_encode($r429['json'])));
    $correctWhileBlocked = login($existing, $password);
    check('T8 active account: even the correct password is refused while throttled (429, no session)', $correctWhileBlocked['status'] === 429
        && $correctWhileBlocked['cookies'] === []);

    // T9 — after the delay: correct password → 200, working session, buckets cleared.
    expire_buckets($existing, '127.0.0.1');
    $ok = login($existing, $password);
    $sid = null;
    foreach ($ok['cookies'] as $c) {
        if (preg_match('/^' . preg_quote($cookieName, '/') . '=([^;]+)/', $c, $mm) === 1 && $mm[1] !== 'deleted') {
            $sid = $mm[1];
        }
    }
    check('T9 after the delay: correct password → 200 with a session cookie', $ok['status'] === 200 && ($ok['json']['success'] ?? false) === true && $sid !== null);
    check('T9 the new session works (GET /api/metrics → 200)', $sid !== null && api_status('/api/metrics', $sid) === 200);
    check('T9 successful sign-in clears both buckets', bucket('account', $existing, '127.0.0.1') === null && bucket('source_account', $existing, '127.0.0.1') === null);
    check('T9 wrong password right after success → 401 (fresh budget), not 429', login($existing, 'wrong-' . bin2hex(random_bytes(6)))['status'] === 401);

    // T10 — 10 concurrent attempts: exactly the 5 free ones pass, whatever the interleaving.
    foreach (['unknown identifier' => "{$m}_race", 'existing account (bcrypt path)' => $names[$user2]] as $label => $ident) {
        $burst = login_many(array_fill(0, 10, ['identifier' => $ident, 'password' => 'wrong-race']));
        $codes = array_count_values(array_column($burst, 'status'));
        ksort($codes);
        $stored = bucket('source_account', $ident, '127.0.0.1');
        check("T10 10 concurrent attempts, $label → exactly 5 × 401 and 5 × 429, counter = 5", $codes === [401 => 5, 429 => 5]
            && (int) ($stored['attempts'] ?? 0) === 5, json_encode([$codes, $stored['attempts'] ?? null]));
    }

    // T11 — the password never reaches the table.
    $raw = json_encode(tk_pdo()->query('SELECT * FROM login_throttle')->fetchAll());
    check('T11 no password, hash, identifier or address in login_throttle after HTTP attempts', !str_contains($raw, $password) && !str_contains($raw, 'wrong-')
        && !str_contains($raw, $existing) && !str_contains($raw, '127.0.0.1') && !str_contains($raw, substr($hash, 7, 20)));

    // Status and accounts untouched: throttling never locks.
    $statuses = tk_pdo()->query("SELECT status FROM users WHERE id IN ($user, $user2)")->fetchAll(PDO::FETCH_COLUMN);
    check('no account status changed by throttling (still active)', $statuses === ['active', 'active']);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    array_map('unlink', glob("$store/sess_*") ?: []);
    @rmdir($store);
    tk_cleanup();
}

tk_finish($before);
