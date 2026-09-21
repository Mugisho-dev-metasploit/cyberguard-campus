<?php

declare(strict_types=1);

/**
 * Runs every backend test suite (backend/tests/*Test.php), each in its own PHP process,
 * prints one line per suite, the failing checks, and the database counts before/after.
 *
 * Run: /opt/lampp/bin/php backend/tests/run.php   (exit 0 = every suite passed)
 */

// Developer/CI tool only: never runs under a web SAPI (checked before anything else).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/support/bootstrap.php';

$suites = glob(__DIR__ . '/*Test.php') ?: [];
sort($suites);

$before = tk_counts();
$failedSuites = 0;
$totalChecks = 0;
$passedChecks = 0;

foreach ($suites as $suite) {
    $start = microtime(true);
    $process = proc_open([PHP_BINARY, $suite], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    preg_match('/(\d+)\/(\d+) checks passed/', $output, $m);
    $passed = (int) ($m[1] ?? 0);
    $total = (int) ($m[2] ?? 0);
    $totalChecks += $total;
    $passedChecks += $passed;
    $ok = $exit === 0 && $total > 0 && $passed === $total;
    $failedSuites += $ok ? 0 : 1;

    printf("%-4s %-34s %s  (%.1fs)\n", $ok ? 'PASS' : 'FAIL', basename($suite), $total > 0 ? "$passed/$total" : "exit $exit, no summary", microtime(true) - $start);

    if (!$ok) {
        foreach (preg_split('/\R/', $output) as $line) {
            if (str_starts_with($line, 'FAIL ') || str_contains($line, 'Fatal') || str_contains($line, 'Uncaught')) {
                echo "       $line\n";
            }
        }
    }
}

$after = tk_counts();
echo "\nDatabase before: " . json_encode($before) . "\nDatabase after:  " . json_encode($after) . "\n";

if ($after !== $before) {
    echo "FAIL database not back to its initial state\n";
    $failedSuites++;
}

printf("\n%d suite(s), %d/%d checks passed, %d suite(s) failed\n", count($suites), $passedChecks, $totalChecks, $failedSuites);
exit($failedSuites === 0 ? 0 : 1);
