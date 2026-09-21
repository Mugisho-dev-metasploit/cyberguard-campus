<?php

declare(strict_types=1);

/**
 * APP-07.4.3 — the submitted password never appears in a logged exception trace.
 *
 * A real exception is raised inside the sign-in path after the password was received: the real
 * LoginController and AuthenticationService run in a separate PHP process (support/
 * login_trace_probe.php) whose user lookup fails, and the exception is left uncaught, so PHP
 * logs it with its stack trace. (Until APP-07.4.4 an identifier larger than max_allowed_packet
 * did this over HTTP; oversized identifiers are now refused before any query.)
 *
 * Checked with the production trace setting (zend.exception_string_param_max_len = 15) and the
 * worst case (1000000: whole strings), each process logging to a private file. Each password is
 * random for this run (short, long, special characters, hash-like). Nothing is written to the
 * database.
 *
 * Run: /opt/lampp/bin/php backend/tests/SensitiveLoginTraceTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Services\AuthenticationService;

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-trace-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$reference = (new ReflectionClassConstant(AuthenticationService::class, 'REFERENCE_HASH'))->getValue();

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

/**
 * Runs one sign-in in the probe process (uncaught exception), logging to $log.
 * @return array{0: int, 1: string} exit code and standard output
 */
function probe(string $log, int $maxLen, string $identifier, string $password): array
{
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log",
        '-d', 'zend.exception_ignore_args=0', '-d', "zend.exception_string_param_max_len=$maxLen", __DIR__ . '/support/login_trace_probe.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['identifier' => $identifier, 'password' => $password], JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** Checks one batch of attempts against the log text written during that batch. */
function assess(string $where, string $log, array $attempts, string $reference): void
{
    foreach ($attempts as $label => [$identifier, $password, $status, $body]) {
        $tag = substr($identifier, 0, 13);   // the identifier's own prefix: finds this request's trace
        check("$where, $label password: the sign-in ended with the uncaught exception (exit 255), nothing printed", $status === 255 && $body === '', "$status");
        check("$where, $label password: the exception trace went through AuthenticationService->authenticate()",
            preg_match('/AuthenticationService->authenticate\(\'' . preg_quote($tag, '/') . '/', $log) === 1);
        check("$where, $label password: the password argument is redacted (SensitiveParameterValue)",
            preg_match('/AuthenticationService->authenticate\(\'' . preg_quote($tag, '/') . '[^\n]*Object\(SensitiveParameterValue\)\)/', $log) === 1);
        check("$where, $label password: not in the log, whole or in part (no 6-character window)", leaks($log, $password) === [], implode(', ', leaks($log, $password)));
    }

    check("$where: no bcrypt hash (\$2a/\$2b/\$2y), reference hash included, in the log", preg_match('/\$2[aby]\$/', $log) === 0
        && !str_contains($log, $reference) && !str_contains($log, substr($reference, 7, 22)));
}

try {
    foreach (['max_len 15 (production setting)' => 15, 'max_len 1000000 (worst case)' => 1000000] as $where => $maxLen) {
        $log = "$dir/php-error-$maxLen.log";
        $attempts = [];
        foreach (passwords() as $label => $password) {
            $identifier = tk_marker() . '_' . count($attempts) . '_' . bin2hex(random_bytes(4));
            [$status, $body] = probe($log, $maxLen, $identifier, $password);
            $attempts[$label] = [$identifier, $password, $status, $body];
        }
        clearstatcache();
        assess($where, is_file($log) ? (string) file_get_contents($log) : '', $attempts, $reference);
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
