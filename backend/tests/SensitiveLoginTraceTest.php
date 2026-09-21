<?php

declare(strict_types=1);

/**
 * APP-07.4.3 — the submitted password never appears in a logged exception trace.
 *
 * A real exception is raised inside the sign-in path after the password was received: the
 * identifier is longer than MariaDB's max_allowed_packet, so the user lookup inside
 * AuthenticationService::authenticate() throws (the throttle, which truncates identifiers,
 * runs first and is unaffected). The uncaught exception is logged by PHP with its stack trace.
 *
 * Checked on the real front controller:
 *  - on a private PHP server logging to a private file, with the production setting
 *    (zend.exception_string_param_max_len = 15) and the worst case (1000000: whole strings);
 *  - on the running Apache, reading only the php_error_log lines written during this run.
 * Each password is random for this run (short, long, special characters, hash-like). No user
 * is created; the throttle buckets of the attempts are removed at the end.
 *
 * Run: /opt/lampp/bin/php backend/tests/SensitiveLoginTraceTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Services\AuthenticationService;

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-trace-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$reference = (new ReflectionClassConstant(AuthenticationService::class, 'REFERENCE_HASH'))->getValue();
$packet = (int) tk_pdo()->query('SELECT @@max_allowed_packet')->fetchColumn();
$padding = str_repeat('x', $packet + (1 << 20));   // makes the user lookup fail, after the password was received

/** @return array<string, string> label => random password (each contains a unique random core) */
function passwords(): array
{
    $core = static fn (int $bytes): string => bin2hex(random_bytes($bytes));

    return [
        'short' => 'Q' . $core(4),                                                     // 9 characters
        'long' => $core(64) . '-' . $core(64),                                         // 257 characters
        'special characters' => "p@ss 'wö\"rd' \\ <>&%;" . $core(8) . " 🔑",
        'hash-like' => '$2y$12$' . substr(strtr(base64_encode(random_bytes(40)), '+', '.'), 0, 53),
        'unique random' => $core(16),
    ];
}

/** Every 6-character window of the password: none may appear in the log (covers any prefix). */
function leaks(string $log, string $password): array
{
    $found = str_contains($log, $password) ? ['full password'] : [];
    $chars = mb_str_split($password);

    for ($i = 0; $i + 6 <= count($chars); $i++) {
        $window = implode('', array_slice($chars, $i, 6));

        if (str_contains($log, $window)) {
            $found[] = "6-character window at $i";
        }
    }

    return $found;
}

/** @return array{0: int, 1: string} */
function post_login(string $url, string $identifier, string $password): array
{
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['identifier' => $identifier, 'password' => $password], JSON_UNESCAPED_UNICODE), 'ignore_errors' => true, 'timeout' => 60]]);
    $raw = (string) @file_get_contents($url, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), $raw];
}

/** Checks one batch of attempts against the log text written during that batch. */
function assess(string $where, string $log, array $attempts, string $reference): void
{
    foreach ($attempts as $label => [$identifier, $password, $status, $body]) {
        $tag = substr($identifier, 0, 13);   // the identifier's own prefix: finds this request's trace
        check("$where, $label password: request failed as before (500, empty body)", $status === 500 && $body === '', "$status");
        check("$where, $label password: the exception trace went through AuthenticationService->authenticate()",
            preg_match('/AuthenticationService->authenticate\(\'' . preg_quote($tag, '/') . '/', $log) === 1);
        check("$where, $label password: the password argument is redacted (SensitiveParameterValue)",
            preg_match('/AuthenticationService->authenticate\(\'' . preg_quote($tag, '/') . '[^\n]*Object\(SensitiveParameterValue\)\)/', $log) === 1);
        check("$where, $label password: not in the log, whole or in part (no 6-character window)", leaks($log, $password) === [], implode(', ', leaks($log, $password)));
    }

    check("$where: no bcrypt hash (\$2a/\$2b/\$2y), reference hash included, in the log", preg_match('/\$2[aby]\$/', $log) === 0
        && !str_contains($log, $reference) && !str_contains($log, substr($reference, 7, 22)));
}

$servers = [];

try {
    /* Private PHP server, private log: production setting and worst case --------------------------- */
    foreach (['max_len 15 (production setting)' => 15, 'max_len 1000000 (worst case)' => 1000000] as $where => $maxLen) {
        $log = "$dir/php-error-$maxLen.log";
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $servers[] = $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log",
            '-d', 'zend.exception_ignore_args=0', '-d', "zend.exception_string_param_max_len=$maxLen", '-d', "session.save_path=$dir",
            '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }

        $attempts = [];
        foreach (passwords() as $label => $password) {
            $identifier = tk_marker() . '_' . count($attempts) . $padding;
            [$status, $body] = post_login("http://127.0.0.1:$port/index.php/login", $identifier, $password);
            $attempts[$label] = [$identifier, $password, $status, $body];
        }
        proc_terminate($server);
        proc_close($server);
        clearstatcache();
        assess($where, is_file($log) ? (string) file_get_contents($log) : '', $attempts, $reference);
    }

    /* Running Apache: only the php_error_log lines written during this run ------------------------- */
    $base = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/');
    $apacheLog = '/opt/lampp/logs/php_error_log';
    clearstatcache();
    $offset = is_file($apacheLog) ? filesize($apacheLog) : 0;
    $attempts = [];
    foreach (passwords() as $label => $password) {
        $identifier = tk_marker() . '_' . (5 + count($attempts)) . $padding;
        [$status, $body] = post_login("$base/backend/public/index.php/login", $identifier, $password);
        $attempts[$label] = [$identifier, $password, $status, $body];
    }
    usleep(500000);
    clearstatcache();
    $new = is_file($apacheLog) ? (string) file_get_contents($apacheLog, false, null, $offset) : '';
    assess('Apache php_error_log (new lines only)', $new, $attempts, $reference);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
