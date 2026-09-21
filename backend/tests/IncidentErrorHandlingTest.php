<?php

declare(strict_types=1);

/**
 * APP-06.8 — error responses of IncidentController (APP-06.5 contract), update and show.
 *
 * The real controller, service, repository and SessionManager (a session is written to a
 * private session store). Internal failures are raised by a PDO test double when the locked or
 * detail read is prepared, with messages full of internal details; the client response must be
 * the generic 500 of the existing contract and contain none of them. Also checks the 404 path,
 * the service-written 422 messages, 400 and the 401 guard of the controller.
 *
 * Run: /opt/lampp/bin/php backend/tests/IncidentErrorHandlingTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\IncidentController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\IncidentRepository;
use CyberGuard\Campus\Services\IncidentService;

require __DIR__ . '/support/bootstrap.php';

ob_start(); // CLI: keep output buffered so session headers can still be emitted

const INTERNAL = 'SQLSTATE[HY000] [2002] database cyberguard, table incidents, column title, host db-internal.campus.local, '
    . 'file /opt/lampp/htdocs/cyberguard-campus/backend/src/X.php, password=hunter2 secret=s3cr3t token=abc123';
const LEAKS = ['SQLSTATE', 'database', 'cyberguard', 'table', 'column', 'db-internal', '/opt/', '.php', 'password', 'hunter2',
    'secret', 's3cr3t', 'token', 'abc123', 'Exception', 'PDO', 'Stack', '#0'];

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-err-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
ini_set('session.save_path', $store);
ini_set('session.gc_probability', '0');
$throwing = null;

function plant(string $store, array $data): string
{
    $sid = 'tk' . bin2hex(random_bytes(16));
    $encoded = '';
    foreach ($data as $key => $value) {
        $encoded .= $key . '|' . serialize($value);
    }
    file_put_contents("$store/sess_$sid", $encoded);

    return $sid;
}

/** One controller call with the session presented as the browser would (cookie). */
function call(PDO $connection, ?string $sid, string $action, string $id, array $body = []): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_SESSION = [];
    foreach (array_keys($_COOKIE) as $key) {
        unset($_COOKIE[$key]);
    }
    if ($sid !== null) {
        $_COOKIE[$_ENV['SESSION_NAME'] ?? 'cyberguard_session'] = $sid;
    }
    session_id($sid ?? '');

    $controller = new IncidentController(new IncidentService(new IncidentRepository($connection)), new SessionManager());
    http_response_code(200);
    ob_start();
    $controller->$action(new HttpRequest($action === 'show' ? 'GET' : 'PATCH', "/api/incidents/$id", $body, ['id' => $id]));
    $raw = (string) ob_get_clean();

    return [http_response_code(), $raw, json_decode($raw, true)];
}

function leaks(string $raw): array
{
    return array_values(array_filter(LEAKS, static fn (string $needle): bool => stripos($raw, $needle) !== false));
}

try {
    $admin = tk_user('admin');
    $session = plant($store, ['user_id' => $admin, 'user_uuid' => tk_uuid(), 'role' => 'admin', 'authenticated' => true, '__session_started' => time() - 60]);
    $noRole = plant($store, ['user_id' => $admin, 'user_uuid' => tk_uuid(), 'authenticated' => true, '__session_started' => time() - 60]);
    $id = (string) tk_incident('open');
    $initial = tk_row((int) $id);

    $throwing = tk_connect(TkThrowingPdo::class);
    $failures = [
        'PDOException' => static fn (): Throwable => new PDOException(INTERNAL),
        'PDOException with int code 404' => static fn (): Throwable => new PDOException(INTERNAL, 404),
        'RuntimeException code 500' => static fn (): Throwable => new RuntimeException(INTERNAL, 500),
        'RuntimeException code 422' => static fn (): Throwable => new RuntimeException(INTERNAL, 422),
        'LogicException' => static fn (): Throwable => new LogicException(INTERNAL),
        'TypeError' => static fn (): Throwable => new TypeError(INTERNAL),
    ];

    foreach (['update' => ['FOR UPDATE', 'Unable to update incident.'], 'show' => ['FROM incidents i', 'Unable to retrieve incident.']] as $action => [$needle, $message]) {
        foreach ($failures as $label => $factory) {
            $throwing->failOn = $needle;
            $throwing->throw = $factory;
            [$status, $raw, $json] = call($throwing, $session, $action, $id, ['status' => 'acknowledged']);
            check("$action: internal $label → 500 '$message', no internal detail",
                $status === 500 && $json === ['success' => false, 'message' => $message] && leaks($raw) === [], "$status $raw " . implode(',', leaks($raw)));
        }
    }

    $throwing->failOn = 'FROM incident_history h';
    $throwing->throw = static fn (): Throwable => new PDOException(INTERNAL);
    [$status, $raw] = call($throwing, $session, 'show', $id);
    check('show: history query failure → 500 generic, no leak', $status === 500 && leaks($raw) === [], $raw);

    foreach (['update' => 'FOR UPDATE', 'show' => 'FROM incidents i'] as $action => $needle) {
        $throwing->failOn = $needle;
        $throwing->throw = static fn (): Throwable => new RuntimeException(INTERNAL, 404);
        [$status, $raw, $json] = call($throwing, $session, $action, $id, ['status' => 'acknowledged']);
        check("$action: RuntimeException 404 → 404 'Incident not found.' (internal text dropped)",
            $status === 404 && $json === ['success' => false, 'message' => 'Incident not found.'] && leaks($raw) === [], $raw);
    }
    $throwing->failOn = '';
    $throwing->throw = null;
    check('simulated failures wrote nothing', tk_row((int) $id) === $initial && tk_history((int) $id) === []);

    $pdo = tk_pdo();
    [$status, , $json] = call($pdo, $session, 'update', '999999999', ['status' => 'acknowledged']);
    check('update unknown incident → 404 Incident not found.', $status === 404 && ($json['message'] ?? '') === 'Incident not found.');
    [$status, , $json] = call($pdo, $session, 'show', '999999999');
    check('show unknown incident → 404 Incident not found.', $status === 404 && ($json['message'] ?? '') === 'Incident not found.');
    [$status, , $json] = call($pdo, $session, 'update', $id, ['status' => 'closed']);
    check('update illegal transition → 422 with the service message', $status === 422 && ($json['message'] ?? '') === 'Status cannot change from open to closed. The next status can only be: acknowledged.');
    [$status, , $json] = call($pdo, $session, 'update', $id, ['status' => 'acknowledged', 'user_id' => 1]);
    check('update client user_id → 422 Unknown field(s): user_id', $status === 422 && ($json['message'] ?? '') === 'Unknown field(s): user_id');
    [$status] = call($pdo, $session, 'update', 'abc', ['status' => 'acknowledged']);
    check('update invalid id → 400', $status === 400);
    [$status] = call($pdo, $session, 'show', '1abc');
    check('show invalid id → 400', $status === 400);
    [$status, , $json] = call($pdo, null, 'update', $id, ['status' => 'acknowledged']);
    check('update without session (controller guard) → 401', $status === 401 && ($json['message'] ?? '') === 'Authentication required.');
    [$status] = call($pdo, $noRole, 'update', $id, ['status' => 'acknowledged']);
    check('update with a session that has no role → 401 (never a default role)', $status === 401);
    [$status] = call($pdo, $noRole, 'update', 'abc', []);
    check('authentication is checked before the id (401, not 400)', $status === 401);
    check('refused requests wrote nothing', tk_row((int) $id) === $initial && tk_history((int) $id) === []);

    // Static contract: no internal exception message is sent for 500 responses.
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/IncidentController.php');
    preg_match_all('/getMessage\(\)/', $source, $uses);
    check('controller source: getMessage() used once, only for the 422 validation branch',
        count($uses[0]) === 1 && str_contains($source, '$this->error($exception->getMessage(), 422);'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $throwing = null;
    array_map('unlink', glob("$store/sess_*") ?: []);
    @rmdir($store);
    tk_cleanup();
}

ob_end_clean();
tk_finish($before);
