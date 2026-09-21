<?php

declare(strict_types=1);

/**
 * APP-07.4.4 — sign-in input bounds.
 *
 * Bounds (AuthenticationService): identifier ≤ 254 characters once trimmed (users.email is
 * VARCHAR(254), users.username VARCHAR(50): nothing longer can match an account), password
 * ≤ 1024 bytes (it never reaches the database). Longer values are rejected whole — never
 * truncated — with the generic 401, before the throttle and before any query.
 *
 * Part A, in process: the real LoginController with a recording PDO under UserRepository, so a
 * rejected input is proven to run no users query, and the real throttle (no bucket consumed).
 * Part B, over HTTP: the real front controller on a private PHP server (private error log and
 * session store): values exactly at the bounds still sign in, values one past are refused,
 * 1 MB / 10 MB inputs answer 401 without any exception. Part C: the running Apache, same
 * oversized inputs (10 MB previously ended in a 500), reading only new php_error_log lines.
 * Temporary users get random passwords for this run; everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/LoginInputValidationTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/support/bootstrap.php';

// The controller is called in process too: keep output buffered so it can still send headers.
ob_start();

$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-input-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$serverLog = "$dir/php-error.log";
$server = null;
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';

const INVALID = ['success' => false, 'message' => 'Invalid credentials.'];
const ID_MAX = AuthenticationService::MAX_IDENTIFIER_LENGTH;
const PW_MAX = AuthenticationService::MAX_PASSWORD_BYTES;

/** A unique identifier of exactly $chars characters, made of $unit (1 character each). */
function ident(int $chars, string $unit = 'a'): string
{
    $prefix = tk_marker() . bin2hex(random_bytes(3)) . '_';

    return $prefix . str_repeat($unit, $chars - strlen($prefix));
}

/** Random password of exactly $bytes bytes. */
function secret(int $bytes): string
{
    return substr(bin2hex(random_bytes(intdiv($bytes, 2) + 1)), 0, $bytes);
}

function throttle_rows(): int
{
    return (int) tk_pdo()->query('SELECT COUNT(*) FROM login_throttle')->fetchColumn();
}

/** @return array{0: int, 1: string} */
function post(string $url, string $json): array
{
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json,
        'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string) @file_get_contents($url, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    $cookies = array_filter($http_response_header ?? [], static fn (string $h): bool => stripos($h, 'Set-Cookie:') === 0);

    return [(int) ($m[1] ?? 0), $raw, $cookies];
}

try {
    /* A. In process: what is rejected never queries users nor consumes the throttle -------------- */
    $recording = tk_connect(TkRecordingPdo::class);
    $controller = new LoginController(new AuthenticationService(new UserRepository($recording)), new SessionManager(),
        new LoginThrottle(new LoginThrottleRepository(tk_pdo())));
    $source = 0;
    $call = static function (array $body) use ($controller, $recording, &$source): array {
        $statements = count($recording->statements);
        $buckets = throttle_rows();
        http_response_code(200);
        ob_start();
        $controller->login(new HttpRequest('POST', '/login', $body, [], true, '198.51.100.' . (++$source % 250)));
        $raw = (string) ob_get_clean();
        $usersQueries = count(array_filter(array_slice($recording->statements, $statements), static fn (string $q): bool => str_contains($q, 'FROM users')));

        return ['status' => http_response_code(), 'raw' => $raw, 'users' => $usersQueries, 'buckets' => throttle_rows() - $buckets];
    };
    $emoji = "\u{1F512}";   // 4 bytes, 1 character

    $rejected = [
        'empty identifier' => ['identifier' => '', 'password' => secret(16)],
        'whitespace identifier' => ['identifier' => " \t ", 'password' => secret(16)],
        'empty password' => ['identifier' => ident(20), 'password' => ''],
        'missing fields' => [],
        'identifier not a string (int)' => ['identifier' => 12345, 'password' => secret(16)],
        'identifier not a string (array)' => ['identifier' => ['a'], 'password' => secret(16)],
        'password not a string (null)' => ['identifier' => ident(20), 'password' => null],
        'password not a string (bool)' => ['identifier' => ident(20), 'password' => true],
        'identifier 255 characters (limit + 1)' => ['identifier' => ident(ID_MAX + 1), 'password' => secret(16)],
        'identifier 255 emoji (limit + 1)' => ['identifier' => ident(ID_MAX + 1, $emoji), 'password' => secret(16)],
        'identifier 1 MB' => ['identifier' => ident(1 << 20), 'password' => secret(16)],
        'identifier 10 MB' => ['identifier' => ident(10 << 20), 'password' => secret(16)],
        'password 1025 bytes (limit + 1)' => ['identifier' => ident(20), 'password' => secret(PW_MAX + 1)],
        'password 257 emoji = 1028 bytes' => ['identifier' => ident(20), 'password' => str_repeat($emoji, 257)],
        'password 1 MB' => ['identifier' => ident(20), 'password' => secret(1 << 20)],
    ];
    foreach ($rejected as $label => $body) {
        $r = $call($body);
        check("A rejected — $label: 401 generic, no users query, no throttle bucket", $r['status'] === 401 && json_decode($r['raw'], true) === INVALID
            && $r['users'] === 0 && $r['buckets'] === 0, json_encode([$r['status'], $r['users'], $r['buckets']]));
    }

    $accepted = [
        'normal identifier and password' => ['identifier' => ident(24), 'password' => secret(20)],
        'identifier 253 characters (limit − 1)' => ['identifier' => ident(ID_MAX - 1), 'password' => secret(16)],
        'identifier 254 characters (at limit)' => ['identifier' => ident(ID_MAX), 'password' => secret(16)],
        'identifier 254 emoji = 1016 bytes (at limit)' => ['identifier' => ident(ID_MAX, $emoji), 'password' => secret(16)],
        'identifier with accents (Unicode)' => ['identifier' => ident(30, 'é'), 'password' => secret(16)],
        'identifier with a NUL byte' => ['identifier' => ident(20) . "\0x", 'password' => secret(16)],
        'identifier padded with 10 000 spaces (trimmed to 40)' => ['identifier' => str_repeat(' ', 5000) . ident(40) . str_repeat(' ', 5000), 'password' => secret(16)],
        'password 1023 bytes (limit − 1)' => ['identifier' => ident(20), 'password' => secret(PW_MAX - 1)],
        'password 1024 bytes (at limit)' => ['identifier' => ident(20), 'password' => secret(PW_MAX)],
        'password 256 emoji = 1024 bytes (at limit)' => ['identifier' => ident(20), 'password' => str_repeat($emoji, 256)],
        'password with a NUL byte' => ['identifier' => ident(20), 'password' => "a\0" . secret(10)],
    ];
    foreach ($accepted as $label => $body) {
        $r = $call($body);
        check("A accepted — $label: throttle then one users query, 401 (unknown account)", $r['status'] === 401 && json_decode($r['raw'], true) === INVALID
            && $r['users'] === 1 && $r['buckets'] === 2, json_encode([$r['status'], $r['users'], $r['buckets']]));
    }

    // Order with the throttle (APP-07.4.1 unchanged): valid attempts are counted; oversized ones are
    // refused before it, like empty ones already were, and use none of the budget.
    $id = ident(20);
    $codes = [];
    for ($i = 1; $i <= 6; $i++) {
        http_response_code(200);
        ob_start();
        $controller->login(new HttpRequest('POST', '/login', ['identifier' => $id, 'password' => secret(16)], [], true, '198.51.100.251'));
        ob_end_clean();
        $codes[] = http_response_code();
    }
    check('A throttle unchanged: 5 × 401 then 429 for valid-size attempts', $codes === [401, 401, 401, 401, 401, 429], json_encode($codes));
    $id2 = ident(20);
    for ($i = 1; $i <= 8; $i++) {
        $call(['identifier' => $id2, 'password' => secret(PW_MAX + 1)]);
    }
    http_response_code(200);
    ob_start();
    $controller->login(new HttpRequest('POST', '/login', ['identifier' => $id2, 'password' => secret(16)], [], true, (string) '198.51.100.' . ($source % 250)));
    ob_end_clean();
    check('A oversized attempts consume no throttle budget (a valid attempt afterwards → 401, not 429)', http_response_code() === 401);

    /* B. Over HTTP, private server: bounds end to end, no exception ------------------------------ */
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $url = "http://127.0.0.1:$port/index.php/login";
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$serverLog", '-d', "session.save_path=$dir",
        '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    // Accounts: one with an email exactly at the identifier bound, one with a password exactly at the password bound.
    $normalPassword = secret(24);
    $longPassword = secret(PW_MAX);
    $a = tk_user('analyst');
    $b = tk_user('viewer');
    $email254 = ident(ID_MAX - strlen('@example.test')) . '@example.test';
    tk_pdo()->prepare('UPDATE users SET email = :e, password_hash = :h WHERE id = :id')
        ->execute(['e' => $email254, 'h' => password_hash($normalPassword, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $a]);
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
        ->execute(['h' => password_hash($longPassword, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $b]);
    $usernameA = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $a")->fetchColumn();
    $usernameB = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $b")->fetchColumn();

    [$s] = post($url, json_encode(['identifier' => $usernameA, 'password' => $normalPassword]));
    check('B normal username + normal password → 200 (unchanged)', $s === 200);
    [$s] = post($url, json_encode(['identifier' => $email254, 'password' => $normalPassword]));
    check('B email of exactly 254 characters → 200 (at the bound, not truncated)', $s === 200 && mb_strlen($email254) === ID_MAX);
    [$s] = post($url, json_encode(['identifier' => $email254 . 'x', 'password' => $normalPassword]));
    check('B the same email + 1 character (255) → 401, never matched under a truncated form', $s === 401);
    [$s] = post($url, json_encode(['identifier' => $usernameB, 'password' => $longPassword]));
    check('B password of exactly 1024 bytes → 200 (at the bound)', $s === 200 && strlen($longPassword) === PW_MAX);
    [$s] = post($url, json_encode(['identifier' => $usernameB, 'password' => $longPassword . 'x']));
    check('B the correct 1024-byte password + 1 byte (1025) → 401, rejected whole, not truncated', $s === 401);

    $sessionsBefore = count(glob("$dir/sess_*") ?: []);
    $oversized = [
        'identifier 1 MB' => ['identifier' => ident(1 << 20), 'password' => secret(16)],
        'identifier 10 MB' => ['identifier' => ident(10 << 20), 'password' => secret(16)],
        'password 1 MB' => ['identifier' => ident(20), 'password' => secret(1 << 20)],
    ];
    $bodies = [];
    foreach ($oversized as $label => $body) {
        [$s, $raw, $cookies] = post($url, json_encode($body));
        $bodies[] = $raw;
        check("B $label → 401 generic, no cookie, no value echoed", $s === 401 && json_decode($raw, true) === INVALID && $cookies === []
            && !str_contains($raw, tk_marker()) && !str_contains($raw, substr($body['password'], 0, 8)), "$s");
    }
    clearstatcache();
    check('B no session created by any rejected input', count(glob("$dir/sess_*") ?: []) === $sessionsBefore);
    proc_terminate($server);
    proc_close($server);
    $server = null;
    clearstatcache();
    $log = is_file($serverLog) ? (string) file_get_contents($serverLog) : '';
    check('B no exception, fatal error, PDO or packet error in the server error log', preg_match('/PDOException|Fatal|Uncaught|max_allowed_packet|SQLSTATE/i', $log) === 0,
        strlen($log) . ' bytes');
    check('B no identifier or password in the server error log', !str_contains($log, tk_marker()) && !str_contains($log, $normalPassword) && !str_contains($log, $longPassword));

    /* C. Running Apache: 10 MB no longer ends in a 500 --------------------------------------------- */
    $base = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/');
    $apacheLog = '/opt/lampp/logs/php_error_log';
    clearstatcache();
    $offset = is_file($apacheLog) ? filesize($apacheLog) : 0;
    $apache = [];
    foreach (['identifier 9 MB' => ['identifier' => ident(9 << 20), 'password' => secret(16)], 'identifier 10 MB' => $oversized['identifier 10 MB'],
        'password 1 MB' => $oversized['password 1 MB']] as $label => $body) {
        [$s, $raw] = post("$base/backend/public/index.php/login", json_encode($body));
        $apache[$label] = $s;
        check("C Apache, $label → 401 generic (was 500 for ≥ 9 MB identifiers)", $s === 401 && json_decode($raw, true) === INVALID, "$s");
    }
    usleep(500000);
    clearstatcache();
    $new = is_file($apacheLog) ? (string) file_get_contents($apacheLog, false, null, $offset) : '';
    check('C Apache php_error_log: no new line at all for these requests (no exception, no value)', !str_contains($new, 'cyberguard-campus')
        && !str_contains($new, tk_marker()), strlen($new) . ' bytes');
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
