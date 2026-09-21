<?php

declare(strict_types=1);

/**
 * APP-07.4.2 — no account enumeration through sign-in timing.
 *
 * Every attempt that reaches AuthenticationService performs exactly one bcrypt verification:
 * against the account's hash when it is active, otherwise against a reference hash. Checked
 *  - in process, on the configured database: timing of unknown / inactive / locked / deleted /
 *    active + wrong password against one bcrypt verification (interleaved, medians), properties
 *    of the reference hash, and — with a recording PDO — that a throttled attempt (429) never
 *    reaches the users table, hence never runs bcrypt;
 *  - over HTTP, real front controller on a private PHP server: identical 401 responses, no
 *    session, successful sign-in still 200, and nothing sensitive in the logs.
 * Temporary users get a random password for this run; everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/LoginTimingTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/support/bootstrap.php';

// The controller is also called in process: keep output buffered so it can still send headers.
ob_start();

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-timing-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
$serverLog = "$store/server-stderr.log";
$server = null;
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
$phpErrorLog = (string) ini_get('error_log');
$phpErrorLogSize = is_file($phpErrorLog) ? filesize($phpErrorLog) : 0;

const INVALID = ['success' => false, 'message' => 'Invalid credentials.'];

$reference = (new ReflectionClassConstant(AuthenticationService::class, 'REFERENCE_HASH'))->getValue();

function median(array $values): float
{
    sort($values);

    return $values[intdiv(count($values), 2)];
}

/** @return array{status: int, raw: string, json: mixed, headers: array<string, string>, cookies: list<string>} */
function login(string $identifier, string $password): array
{
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode(['identifier' => $identifier, 'password' => $password]), 'ignore_errors' => true, 'timeout' => 30]]);
    $raw = (string) @file_get_contents($GLOBALS['base'] . '/login', false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    $headers = [];
    $cookies = [];
    foreach (array_slice($http_response_header ?? [], 1) as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        strtolower($name) === 'set-cookie' ? $cookies[] = $value : $headers[strtolower($name)] = $value;
    }

    return ['status' => (int) ($m[1] ?? 0), 'raw' => $raw, 'json' => json_decode($raw, true), 'headers' => $headers, 'cookies' => $cookies];
}

try {
    /* Fixtures: one account per state, all with the same random password for this run ---------- */
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);   // same algorithm/cost as the stored accounts
    $ids = ['active' => tk_user('analyst'), 'inactive' => tk_user('analyst'), 'locked' => tk_user('analyst'), 'deleted' => tk_user('analyst')];
    $pdo = tk_pdo();
    $pdo->prepare('UPDATE users SET password_hash = :h WHERE username LIKE :m')->execute(['h' => $hash, 'm' => tk_marker() . '%']);
    $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :id")->execute(['id' => $ids['inactive']]);
    $pdo->prepare("UPDATE users SET status = 'locked' WHERE id = :id")->execute(['id' => $ids['locked']]);
    $pdo->prepare('UPDATE users SET deleted_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $ids['deleted']]);
    $names = $pdo->query('SELECT id, username FROM users WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
    $name = static fn (string $state): string => $names[$ids[$state]];
    $unknown = static fn (): string => tk_marker() . '_nx' . bin2hex(random_bytes(3));

    /* Reference hash ---------------------------------------------------------------------------- */
    $info = password_get_info($reference);
    $storedCosts = array_map(static fn (string $h): int => (int) (password_get_info($h)['options']['cost'] ?? 0),
        $pdo->query("SELECT password_hash FROM users WHERE username NOT LIKE '" . tk_marker() . "%'")->fetchAll(PDO::FETCH_COLUMN));
    check('reference hash: bcrypt, cost 12, not below any stored hash nor PASSWORD_DEFAULT', $info['algoName'] === 'bcrypt'
        && ($info['options']['cost'] ?? 0) === 12 && ($storedCosts === [] || max($storedCosts) <= 12)
        && 12 >= (int) (password_get_info(password_hash('x', PASSWORD_DEFAULT))['options']['cost'] ?? 99));
    $inDb = $pdo->prepare('SELECT COUNT(*) FROM users WHERE password_hash = :h');
    $inDb->execute(['h' => $reference]);
    check('reference hash is not stored in users', (int) $inDb->fetchColumn() === 0);
    $env = (string) @file_get_contents(dirname(__DIR__, 2) . '/.env');
    $migrations = implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: []));
    check('reference hash is not in .env nor in any migration', !str_contains($env, $reference) && !str_contains($migrations, $reference));

    /* In process: business rules and timing ----------------------------------------------------- */
    $service = new AuthenticationService(new UserRepository($pdo));
    $outcomes = [
        'active + correct' => $service->authenticate($name('active'), $password)->isAuthenticated(),
        'inactive + correct' => $service->authenticate($name('inactive'), $password)->isAuthenticated(),
        'locked + correct' => $service->authenticate($name('locked'), $password)->isAuthenticated(),
        'deleted + correct' => $service->authenticate($name('deleted'), $password)->isAuthenticated(),
        'active + wrong' => $service->authenticate($name('active'), 'wrong-' . bin2hex(random_bytes(6)))->isAuthenticated(),
    ];
    check('rules unchanged: only active + correct password signs in', $outcomes === ['active + correct' => true, 'inactive + correct' => false,
        'locked + correct' => false, 'deleted + correct' => false, 'active + wrong' => false], json_encode($outcomes));

    $cases = [
        'unknown' => static fn (): array => [$unknown(), 'wrong-' . bin2hex(random_bytes(6))],
        'active + wrong password' => static fn (): array => [$name('active'), 'wrong-' . bin2hex(random_bytes(6))],
        'inactive + correct password' => static fn (): array => [$name('inactive'), $password],
        'locked + correct password' => static fn (): array => [$name('locked'), $password],
        'deleted + correct password' => static fn (): array => [$name('deleted'), $password],
    ];
    $times = ['one bcrypt verification' => []];
    for ($round = 0; $round < 8; $round++) {
        $order = array_keys($cases);
        shuffle($order);
        $s = hrtime(true);
        password_verify('wrong-' . bin2hex(random_bytes(6)), $hash);
        $times['one bcrypt verification'][] = (hrtime(true) - $s) / 1e6;
        foreach ($order as $label) {
            [$identifier, $secret] = $cases[$label]();
            $s = hrtime(true);
            $service->authenticate($identifier, $secret);
            $times[$label][] = (hrtime(true) - $s) / 1e6;
        }
    }
    $medians = array_map('median', $times);
    $unit = $medians['one bcrypt verification'];
    $ratios = array_map(static fn (float $m): float => round($m / $unit, 2), $medians);
    echo 'timing (in process, median ms): ' . implode(', ', array_map(static fn (string $k, float $m): string => sprintf('%s %.1f', $k, $m),
        array_keys($medians), $medians)) . PHP_EOL;
    $outside = array_filter($ratios, static fn (float $r): bool => $r < 0.6 || $r > 1.6);
    check('every failure case costs about one bcrypt verification (0.6×–1.6×), no fast path left', $outside === [], json_encode($ratios));
    check('existing active account: one verification, not two (below 1.6× one bcrypt)', $ratios['active + wrong password'] < 1.6, json_encode($ratios));
    $spread = max(array_slice($medians, 1)) / min(array_slice($medians, 1));
    check('unknown, inactive, locked, deleted and active + wrong password within 1.4× of each other', $spread < 1.4, (string) round($spread, 2));

    /* T9 — throttle first: a 429 never reaches the users table, hence never runs bcrypt ---------- */
    $recording = tk_connect(TkRecordingPdo::class);
    $controller = new LoginController(new AuthenticationService(new UserRepository($recording)), new SessionManager(),
        new LoginThrottle(new LoginThrottleRepository(tk_pdo())));
    $call = static function (string $identifier) use ($controller): array {
        http_response_code(200);
        ob_start();
        $s = hrtime(true);
        $controller->login(new HttpRequest('POST', '/login', ['identifier' => $identifier, 'password' => 'wrong-throttle'], [], true, '198.51.100.42'));
        $ms = (hrtime(true) - $s) / 1e6;
        $body = (string) ob_get_clean();

        return [http_response_code(), json_decode($body, true), $ms];
    };
    $throttled = $name('active');
    $statuses = [];
    for ($i = 1; $i <= 5; $i++) {
        $statuses[] = $call($throttled)[0];
    }
    $queriesBefore = count($recording->statements);
    [$status429, $body429, $ms429] = $call($throttled);
    $usersQueries = array_filter(array_slice($recording->statements, $queriesBefore), static fn (string $q): bool => str_contains($q, 'users'));
    check('T9 5 failures → 401, then 429 from the throttle', $statuses === [401, 401, 401, 401, 401] && $status429 === 429
        && $body429 === ['success' => false, 'message' => 'Too many sign-in attempts. Try again later.'], json_encode([$statuses, $status429]));
    check('T9 the 429 never reached AuthenticationService (no users query, so no bcrypt)', $usersQueries === [] && count($recording->statements) === $queriesBefore);
    check('T9 the 429 answers well under one bcrypt verification', $ms429 < $unit / 2, round($ms429, 1) . ' ms vs ' . round($unit, 1) . ' ms');

    /* Over HTTP, real front controller ---------------------------------------------------------- */
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $GLOBALS['base'] = "http://127.0.0.1:$port/index.php";
    $server = proc_open([PHP_BINARY, '-d', "session.save_path=$store", '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $serverLog, 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $failures = [
        'T1 active + wrong password' => login($name('active'), 'wrong-' . bin2hex(random_bytes(6))),
        'T2 unknown + wrong password' => login($unknown(), 'wrong-' . bin2hex(random_bytes(6))),
        'T3 inactive + correct password' => login($name('inactive'), $password),
        'T4 locked + correct password' => login($name('locked'), $password),
        'T5 deleted + correct password' => login($name('deleted'), $password),
    ];
    foreach ($failures as $label => $r) {
        check("$label → 401 generic", $r['status'] === 401 && $r['json'] === INVALID, $r['status'] . ' ' . $r['raw']);
    }
    clearstatcache();
    check('T6 no failure case creates a session (no Set-Cookie, empty session store)', array_merge(...array_column($failures, 'cookies')) === []
        && glob("$store/sess_*") === []);
    $strip = static function (array $r): array {
        unset($r['headers']['date']);

        return [$r['status'], $r['raw'], $r['headers'], $r['cookies']];
    };
    check('T7 identical status, body and headers for all five failure cases', count(array_unique(array_map(static fn ($r) => serialize($strip($r)), $failures))) === 1);
    $ok = login($name('active'), $password);
    check('T8 active + correct password → 200 with a session cookie', $ok['status'] === 200 && ($ok['json']['success'] ?? false) === true
        && ($ok['json']['user']['username'] ?? '') === $name('active') && $ok['cookies'] !== []);
    check('reference hash never appears in an HTTP response', !str_contains(implode('', array_column($failures, 'raw')) . $ok['raw'], $reference)
        && !str_contains(implode('', array_column($failures, 'raw')) . $ok['raw'], '$2y$'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    /* T10 — nothing sensitive written to the logs during the run ---------------------------------- */
    clearstatcache();
    $newPhpLog = is_file($phpErrorLog) ? (string) file_get_contents($phpErrorLog, false, null, $phpErrorLogSize) : '';
    $logs = $newPhpLog . (is_file($serverLog) ? (string) file_get_contents($serverLog) : '');
    $secrets = array_filter([$password ?? '', $reference, $hash ?? '', substr($reference, 7, 22), tk_marker()]);
    check('T10 no password, hash, reference hash or identifier in the PHP error log or server output', array_filter($secrets, static fn ($s) => str_contains($logs, $s)) === [],
        strlen($logs) . ' bytes of new log');

    array_map('unlink', glob("$store/*") ?: []);
    @rmdir($store);
    tk_cleanup();
}

tk_finish($before);
