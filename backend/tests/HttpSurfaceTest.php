<?php

declare(strict_types=1);

/**
 * APP074-12 — a known path requested with another method answers 405 with an Allow header;
 * unknown paths still answer 404 "Route not found.". APP074-13 — the application does not
 * advertise the PHP version (no X-Powered-By). No database write.
 *
 * Over HTTP: a private PHP server (with expose_php on, as in the server configuration) and the
 * running Apache. The Server header belongs to Apache's configuration (ServerTokens), not to
 * the application, and is not asserted here.
 *
 * Run: /opt/lampp/bin/php backend/tests/HttpSurfaceTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$server = null;

/** @return array{status: int, json: mixed, headers: array<string, list<string>>} */
function send(string $url, string $method): array
{
    $c = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\n", 'content' => '',
        'ignore_errors' => true, 'timeout' => 15]]);
    $raw = (string) @file_get_contents($url, false, $c);
    $headers = [];
    foreach (array_slice($http_response_header ?? [], 1) as $l) {
        [$name, $value] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
        $headers[strtolower($name)][] = $value;
    }
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return ['status' => (int) ($m[1] ?? 0), 'json' => json_decode($raw, true), 'headers' => $headers];
}

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'expose_php=1', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $apache = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/') . '/backend/public/index.php';

    $notAllowed = ['success' => false, 'message' => 'Method not allowed.'];
    $cases = [   // path, method, expected Allow
        ['/login', 'GET', 'POST'], ['/login', 'PUT', 'POST'], ['/login', 'PATCH', 'POST'], ['/login', 'DELETE', 'POST'], ['/login', 'OPTIONS', 'POST'],
        ['/logout', 'GET', 'POST'], ['/logout', 'DELETE', 'POST'],
        ['/health', 'POST', 'GET'], ['/health', 'HEAD', 'GET'],
        ['/api/incidents', 'POST', 'GET'], ['/api/incidents/1', 'POST', 'GET, PATCH'], ['/api/incidents/1', 'DELETE', 'GET, PATCH'],
        ['/api/metrics', 'DELETE', 'GET'],
    ];

    foreach (['private server' => "http://127.0.0.1:$port/index.php", 'Apache' => $apache] as $where => $base) {
        $wrong = [];
        foreach ($cases as [$path, $method, $allow]) {
            $r = send("$base$path", $method);
            $ok = $r['status'] === 405 && ($r['headers']['allow'] ?? []) === [$allow] && ($method === 'HEAD' || $r['json'] === $notAllowed);
            if (!$ok) {
                $wrong[] = "$method $path → {$r['status']} " . json_encode($r['headers']['allow'] ?? null);
            }
        }
        check("$where: known path with another method → 405 \"Method not allowed.\" + Allow (" . count($cases) . ' cases)', $wrong === [], implode('; ', $wrong));

        $missing = send("$base/does-not-exist", 'GET');
        $missingPost = send("$base/api/unknown", 'POST');
        check("$where: unknown path → 404 \"Route not found.\" without Allow (unchanged)", $missing['status'] === 404 && $missingPost['status'] === 404
            && $missing['json'] === ['success' => false, 'message' => 'Route not found.'] && !isset($missing['headers']['allow'], $missingPost['headers']['allow']));

        $health = send("$base/health", 'GET');
        $metrics = send("$base/api/metrics", 'GET');
        $logout = send("$base/logout", 'POST');
        check("$where: correct methods unchanged (GET /health 200, GET /api/metrics 401, POST /logout 200)", $health['status'] === 200
            && $metrics['status'] === 401 && $logout['status'] === 200);

        $exposed = [];
        foreach (['GET /health' => $health, 'GET /api/metrics' => $metrics, 'POST /logout' => $logout, '404' => $missing,
            '405' => send("$base/login", 'GET'), 'POST /login 415' => send("$base/login", 'POST')] as $label => $r) {
            if (isset($r['headers']['x-powered-by'])) {
                $exposed[] = $label;
            }
        }
        check("$where: no X-Powered-By header on any application response", $exposed === [], implode(', ', $exposed));
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    tk_cleanup();
}

tk_finish($before);
