<?php

declare(strict_types=1);

/**
 * APP-07.5 — authorization / IDOR / BOLA HTTP audit.
 *
 * Validates the real front controller and middleware stack for global SOC
 * read endpoints:
 *
 *   GET /api/events
 *   GET /api/alerts
 *   GET /api/devices
 *   GET /api/metrics
 *
 * Also validates:
 *   - unauthenticated access -> 401
 *   - expired / forged sessions -> 401
 *   - viewer / analyst / admin read access -> 200
 *   - unsupported HTTP methods -> 405
 *   - JSON response contracts
 *   - absence of sensitive fields / test canary leakage
 *   - read-only behavior
 *
 * Run:
 *   /opt/lampp/bin/php backend/tests/AuthorizationApiTest.php
 */

require __DIR__ . '/support/bootstrap.php';

const AUTHZ_ENDPOINTS = [
    '/api/events' => 'Events retrieved successfully.',
    '/api/alerts' => 'Alerts retrieved successfully.',
    '/api/devices' => 'Devices retrieved successfully.',
    '/api/metrics' => 'Metrics retrieved successfully.',
];

const AUTHZ_SENSITIVE_KEYS = [
    'password',
    'password_hash',
    'email',
    'session',
    'token',
    'secret',
    'metadata',
];

$before = tk_counts();

$store = sys_get_temp_dir() . '/cg-authz-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);

$server = null;
$canary = 'AUTHZ-CANARY-' . bin2hex(random_bytes(4));

function authz_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if ($socket === false) {
        throw new RuntimeException('Unable to allocate a free TCP port.');
    }

    $address = (string) stream_socket_get_name($socket, false);
    $port = (int) substr(strrchr($address, ':'), 1);

    fclose($socket);

    return $port;
}

function authz_plant_session(
    string $store,
    int $userId,
    string $role,
    ?int $startedAt = null,
): string {
    $sid = 'tk' . bin2hex(random_bytes(16));

    $data = [
        'user_id' => $userId,
        'user_uuid' => tk_uuid(),
        'role' => $role,
        'authenticated' => true,
        '__session_started' => $startedAt ?? time() - 60,
    ];

    $encoded = '';

    foreach ($data as $key => $value) {
        $encoded .= $key . '|' . serialize($value);
    }

    file_put_contents(
        "{$store}/sess_{$sid}",
        $encoded,
    );

    return $sid;
}

/**
 * @return array{0:int,1:string,2:mixed}
 */
function authz_http(
    string $method,
    string $path,
    ?string $sid = null,
    ?array $body = null,
): array {
    $headers = "Accept: application/json\r\n";

    if ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
    }

    if ($sid !== null) {
        $sessionName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
        $headers .= "Cookie: {$sessionName}={$sid}\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $raw = (string) @file_get_contents(
        $GLOBALS['authz_base'] . $path,
        false,
        $context,
    );

    preg_match(
        '#^HTTP/\S+ (\d{3})#',
        $http_response_header[0] ?? '',
        $matches,
    );

    return [
        (int) ($matches[1] ?? 0),
        $raw,
        json_decode($raw, true),
    ];
}

/**
 * @return list<string>
 */
function authz_sensitive(
    mixed $value,
    string $canary,
    string $path = '$',
): array {
    $hits = [];

    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if (
                is_string($key)
                && in_array(
                    strtolower($key),
                    AUTHZ_SENSITIVE_KEYS,
                    true,
                )
            ) {
                $hits[] = "{$path}.{$key}";
            }

            $hits = array_merge(
                $hits,
                authz_sensitive(
                    $child,
                    $canary,
                    "{$path}.{$key}",
                ),
            );
        }

        return $hits;
    }

    if (is_string($value)) {
        foreach ([
            $canary,
            'SQLSTATE',
            '$2y$',
            'PDOException',
            '/opt/',
        ] as $needle) {
            if (stripos($value, $needle) !== false) {
                $hits[] = "{$path}=~{$needle}";
            }
        }
    }

    return $hits;
}

try {
    /*
     * Start the real front controller through PHP's built-in server.
     */
    $port = authz_free_port();

    $GLOBALS['authz_base'] =
        "http://127.0.0.1:{$port}/index.php";

    $server = proc_open(
        [
            PHP_BINARY,
            '-d',
            "session.save_path={$store}",
            '-d',
            'display_errors=0',
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            dirname(__DIR__) . '/public',
        ],
        [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
    );

    if (!is_resource($server)) {
        throw new RuntimeException(
            'Unable to start PHP test server.'
        );
    }

    for (
        $i = 0;
        $i < 50
        && @fsockopen('127.0.0.1', $port) === false;
        $i++
    ) {
        usleep(100000);
    }

    /*
     * Create one authenticated session per role.
     */
    $users = [
        'viewer' => tk_user('viewer'),
        'analyst' => tk_user('analyst'),
        'admin' => tk_user('admin'),
    ];

    $sessions = [];

    foreach ($users as $role => $userId) {
        $sessions[$role] = authz_plant_session(
            $store,
            $userId,
            $role,
        );
    }

    /*
     * Add controlled test records containing a canary.
     *
     * The canary is deliberately stored in metadata so that a vulnerable
     * repository/controller that accidentally exposes metadata can be detected.
     */
    $pdo = tk_pdo();

    $pdo->prepare(
        'INSERT INTO devices
        (uuid, hostname, ip_address, device_type, environment, status, metadata)
        VALUES
        (:uuid, :hostname, :ip, \'server\', \'production\', \'online\', :metadata)'
    )->execute([
        'uuid' => tk_uuid(),
        'hostname' => tk_marker() . '-authz-device',
        'ip' => '10.0.0.99',
        'metadata' => json_encode([
            'secret' => $canary,
        ]),
    ]);

    $deviceId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO alerts
        (alert_uuid, device_id, source, alert_type, severity, title, status, detected_at, metadata)
        VALUES
        (:uuid, :device, \'ids\', \'authorization-test\', 4,
         :title, \'new\', \'2026-09-21 09:00:00\', :metadata)'
    )->execute([
        'uuid' => tk_uuid(),
        'device' => $deviceId,
        'title' => tk_marker() . '-authz-alert',
        'metadata' => json_encode([
            'secret' => $canary,
        ]),
    ]);

    /*
     * 1. Anonymous access must be rejected.
     */
    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [$status, , $json] = authz_http(
            'GET',
            $endpoint,
        );

        check(
            "anonymous GET {$endpoint} → 401",
            $status === 401
            && ($json['success'] ?? null) === false
            && ($json['message'] ?? '') === 'Authentication required.',
            (string) $status,
        );
    }

    /*
     * 2. Expired session must be rejected.
     */
    $expired = authz_plant_session(
        $store,
        $users['admin'],
        'admin',
        time() - 21600,
    );

    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [$status, , $json] = authz_http(
            'GET',
            $endpoint,
            $expired,
        );

        check(
            "expired session GET {$endpoint} → 401",
            $status === 401
            && ($json['message'] ?? '') === 'Authentication required.',
            (string) $status,
        );
    }

    /*
     * 3. Forged/nonexistent session must be rejected.
     */
    $forged = 'tk' . str_repeat('0', 32);

    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [$status, , $json] = authz_http(
            'GET',
            $endpoint,
            $forged,
        );

        check(
            "forged session GET {$endpoint} → 401",
            $status === 401
            && ($json['message'] ?? '') === 'Authentication required.',
            (string) $status,
        );
    }

    /*
     * 4. All three legitimate roles may read the global SOC data.
     */
    foreach (['viewer', 'analyst', 'admin'] as $role) {
        foreach (AUTHZ_ENDPOINTS as $endpoint => $message) {
            [$status, $body, $json] = authz_http(
                'GET',
                $endpoint,
                $sessions[$role],
            );

            $validEnvelope =
                $status === 200
                && ($json['success'] ?? null) === true
                && ($json['message'] ?? null) === $message
                && array_key_exists('data', $json);

            check(
                "{$role} GET {$endpoint} → 200 with expected JSON contract",
                $validEnvelope,
                "{$status}: {$body}",
            );

            $hits = authz_sensitive(
                $json,
                $canary,
            );

            check(
                "{$role} GET {$endpoint} → no sensitive data/canary leakage",
                $hits === [],
                implode(', ', array_slice($hits, 0, 5)),
            );
        }
    }

    /*
     * 5. Unsupported methods must not execute the GET handlers.
     *
     * POST/PATCH/DELETE are intentionally tested without a body because
     * the expected response is a router-level 405.
     */
    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            [$status, , $json] = authz_http(
                $method,
                $endpoint,
                $sessions['admin'],
            );

            check(
                "{$method} {$endpoint} → 405",
                $status === 405
                && ($json['success'] ?? null) === false,
                (string) $status,
            );
        }
    }

    /*
     * 6. Route with a fake object identifier must not accidentally resolve.
     *
     * These endpoints do not define dynamic {id} routes. Adding an identifier
     * therefore must result in 404 rather than being silently accepted.
     */
    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [$status] = authz_http(
            'GET',
            $endpoint . '/1',
            $sessions['viewer'],
        );

        check(
            "GET {$endpoint}/1 → 404, no implicit object route",
            $status === 404,
            (string) $status,
        );
    }

    /*
     * 7. Query-string input must not alter authorization or expose metadata.
     */
    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [$status, , $json] = authz_http(
            'GET',
            $endpoint . '?id=1&user_id=1&role=admin',
            $sessions['viewer'],
        );

        $hits = authz_sensitive($json, $canary);

        check(
            "viewer {$endpoint} ignores client authorization parameters",
            $status === 200
            && ($json['success'] ?? null) === true
            && $hits === [],
            $status . ' ' . implode(', ', $hits),
        );
    }

    /*
     * 8. Read operations must not modify the database.
     */
    $snapshot = tk_counts();

    foreach (['viewer', 'analyst', 'admin'] as $role) {
        foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
            authz_http(
                'GET',
                $endpoint,
                $sessions[$role],
            );
        }
    }

    check(
        'all authenticated GETs are read-only',
        tk_counts() === $snapshot,
        json_encode([
            'before' => $snapshot,
            'after' => tk_counts(),
        ]),
    );

    /*
     * 9. Database canary must not appear in any public response.
     */
    $leaks = [];

    foreach (array_keys(AUTHZ_ENDPOINTS) as $endpoint) {
        [, $body] = authz_http(
            'GET',
            $endpoint,
            $sessions['admin'],
        );

        if (stripos($body, $canary) !== false) {
            $leaks[] = $endpoint;
        }
    }

    check(
        'test canary never appears in any global SOC API response',
        $leaks === [],
        implode(', ', $leaks),
    );
} catch (Throwable $e) {
    check(
        'suite ran without an unexpected exception',
        false,
        $e::class . ': ' . $e->getMessage(),
    );
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    array_map(
        'unlink',
        glob("{$store}/sess_*") ?: [],
    );

    @rmdir($store);

    tk_cleanup();
}

tk_finish($before);
