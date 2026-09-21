<?php

declare(strict_types=1);

/**
 * APP-07.2 — web exposure and secrets (non-destructive, no database write).
 *
 * Over HTTP (the running Apache, base URL CG_BASE_URL, default http://localhost/cyberguard-campus):
 * sensitive resources answer 403/404, directory listing is off, the public interface and API
 * still answer. Response bodies are never printed. Also checks that the test bootstrap and
 * runner refuse any non-CLI SAPI, and that .env is ignored and no longer tracked by Git.
 *
 * Run: /opt/lampp/bin/php backend/tests/WebExposureTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$base = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/');
$projectRoot = dirname(__DIR__, 2);
$server = null;

/** @return array{0: int, 1: string} status and body (the body is only inspected, never printed) */
function fetch(string $url): array
{
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = (string) @file_get_contents($url, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), $body];
}

/** True when the non-CLI guard is the first statement executed in the file. */
function guard_first(string $file): bool
{
    $code = (string) file_get_contents($file);
    $guard = strpos($code, "if (PHP_SAPI !== 'cli')");
    $firstRequire = strpos($code, 'require ');

    return $guard !== false && ($firstRequire === false || $guard < $firstRequire);
}

try {
    /* Sensitive resources: 403 or 404 --------------------------------------------------------- */
    $sensitive = ['/.env', '/.env.example', '/.git/', '/.git/HEAD', '/.git/config', '/.gitignore', '/.htaccess',
        '/backend/tests/', '/backend/tests/README.md', '/backend/tests/run.php', '/backend/tests/support/bootstrap.php',
        '/backend/src/', '/backend/src/Core/SessionManager.php', '/backend/database/', '/backend/database/migrations/001_create_users.sql',
        '/backend/composer.json', '/backend/composer.lock', '/backend/vendor/', '/backend/vendor/autoload.php', '/backend/',
        '/backend/public/.htaccess', '/frontend/.htaccess', '/docs/', '/infrastructure/', '/scripts/', '/tests/', '/README.md'];
    $exposed = [];
    foreach ($sensitive as $path) {
        [$status] = fetch($base . $path);
        if (!in_array($status, [403, 404], true)) {
            $exposed[] = "$path → $status";
        }
    }
    check('sensitive resources (.env, .git, tests, src, database, vendor, composer, dotfiles…) → 403/404', $exposed === [], implode('; ', $exposed));

    /* Directory listing disabled ----------------------------------------------------------------- */
    $listings = [];
    foreach (['/', '/backend/', '/frontend/assets/', '/frontend/assets/js/'] as $path) {
        [$status, $body] = fetch($base . $path);
        if ($status === 200 || stripos($body, 'Index of') !== false) {
            $listings[] = "$path → $status";
        }
    }
    check('no automatic directory listing (project root, backend, frontend asset folders)', $listings === [], implode('; ', $listings));

    /* Legitimate resources still served -------------------------------------------------------- */
    $expected = ['/frontend/' => 200, '/frontend/welcome.html' => 200, '/frontend/login.html' => 200, '/frontend/assets/js/api.js' => 200,
        '/backend/public/index.php/health' => 200, '/backend/public/index.php/api/incidents' => 401, '/backend/public/index.php/api/incidents/1' => 401];
    $broken = [];
    foreach ($expected as $path => $want) {
        [$status] = fetch($base . $path);
        if ($status !== $want) {
            $broken[] = "$path → $status (expected $want)";
        }
    }
    check('public interface and API still answer (frontend 200, /health 200, protected API 401)', $broken === [], implode('; ', $broken));

    /* Test tooling refuses any non-CLI SAPI ------------------------------------------------------ */
    $bootstrapFile = __DIR__ . '/support/bootstrap.php';
    $runnerFile = __DIR__ . '/run.php';
    $staticOk = guard_first($bootstrapFile) && guard_first($runnerFile);
    check('bootstrap.php and run.php: non-CLI guard is the first statement', $staticOk);

    if ($staticOk) {
        // cli-server is a non-CLI SAPI: requesting the files must stop at the guard (404, empty body).
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }
        $refused = [];
        foreach (['/support/bootstrap.php', '/run.php'] as $path) {
            [$status, $body] = fetch("http://127.0.0.1:$port$path");
            $refused[$path] = $status === 404 && trim($body) === '';
        }
        check('bootstrap.php refuses a web SAPI (cli-server → 404, nothing executed)', $refused['/support/bootstrap.php'], json_encode($refused));
        check('run.php refuses a web SAPI (cli-server → 404, nothing executed)', $refused['/run.php'], json_encode($refused));
    }

    /* Secrets in Git ------------------------------------------------------------------------------ */
    $gitignore = is_file("$projectRoot/.gitignore") ? (string) file_get_contents("$projectRoot/.gitignore") : '';
    check('.gitignore lists .env', (bool) preg_match('/^\/?\.env\s*$/m', $gitignore));

    $git = proc_open(['git', 'ls-files', '--error-unmatch', '.env'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $projectRoot);
    $tracked = proc_close($git) === 0;
    check('.env is not tracked by Git (run: git rm --cached .env)', !$tracked);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

tk_finish($before);
