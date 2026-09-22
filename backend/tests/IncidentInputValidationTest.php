<?php

declare(strict_types=1);

require_once __DIR__ . '/support/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must run from CLI.\n");
    exit(1);
}

$results = [];

function check076(string $label, bool $condition, string $detail = ''): void
{
    global $results;

    $results[] = [
        'label' => $label,
        'ok' => $condition,
        'detail' => $detail,
    ];
}

function free_port076(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if ($socket === false) {
        throw new RuntimeException('Unable to allocate a free TCP port.');
    }

    $address = stream_socket_get_name($socket, false);

    if ($address === false) {
        fclose($socket);
        throw new RuntimeException('Unable to read allocated TCP port.');
    }

    $port = (int) substr(strrchr($address, ':'), 1);

    fclose($socket);

    return $port;
}

/**
 * @return string
 */
function plant_session076(
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

    if (file_put_contents("$store/sess_$sid", $encoded) === false) {
        throw new RuntimeException('Unable to plant test session.');
    }

    return $sid;
}

/**
 * @return array{0:int,1:string,2:mixed}
 */
function http076(
    string $base,
    string $method,
    string $path,
    ?string $sid = null,
    ?array $body = null,
): array {
    $headers = "Accept: application/json\r\n";
    $headers .= "Content-Type: application/json\r\n";

    if ($sid !== null) {
        $sessionName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
        $headers .= "Cookie: {$sessionName}={$sid}\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body === null
                ? ''
                : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $raw = (string) @file_get_contents(
        $base . $path,
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
 * @return array<string,mixed>
 */
function incident076(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
        SELECT
            id,
            incident_uuid,
            incident_number,
            title,
            description,
            severity,
            status,
            priority,
            assigned_to,
            detected_at,
            acknowledged_at,
            contained_at,
            resolved_at,
            closed_at,
            created_at,
            updated_at
        FROM incidents
        WHERE id = :id
        LIMIT 1
        SQL
    );

    $statement->execute(['id' => $id]);

    $row = $statement->fetch();

    if (!is_array($row)) {
        throw new RuntimeException("Incident {$id} not found during test.");
    }

    return $row;
}

function create076Incident(PDO $pdo, int $assignedTo): int
{
    $statement = $pdo->prepare(
        <<<'SQL'
        INSERT INTO incidents (
            incident_uuid,
            incident_number,
            title,
            description,
            severity,
            status,
            priority,
            assigned_to,
            detected_at
        )
        VALUES (
            :uuid,
            :number,
            :title,
            :description,
            2,
            'open',
            'medium',
            :assigned_to,
            CURRENT_TIMESTAMP(6)
        )
        SQL
    );

    $statement->execute([
        'uuid' => tk_uuid(),
        'number' => tk_marker() . '-076',
        'title' => 'APP076 original title',
        'description' => 'APP076 original description',
        'assigned_to' => $assignedTo,
    ]);

    return (int) $pdo->lastInsertId();
}
$pdo = tk_pdo();

$marker = tk_marker();
$process = null;
$serverPipes = [];
$store = null;

$analystId = null;
$viewerId = null;
$incidentId = null;

$analystSession = null;

try {
    /*
     * ----------------------------------------------------------------------
     * Setup
     * ----------------------------------------------------------------------
     */

    $store = sys_get_temp_dir() . '/cyberguard-app076-' . bin2hex(random_bytes(6));

    if (!mkdir($store, 0700, true) && !is_dir($store)) {
        throw new RuntimeException('Unable to create session store.');
    }

    $analystId = tk_user('analyst');
    $viewerId = tk_user('viewer');

    $incidentId = create076Incident($pdo, $viewerId);

    $before = incident076($pdo, $incidentId);

    $port = free_port076();

    $base = "http://127.0.0.1:$port";

    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            "session.save_path=$store",
            '-d',
            'display_errors=0',
            '-S',
            "127.0.0.1:$port",
            '-t',
            dirname(__DIR__) . '/public',
        ],
        [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $serverPipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP test server.');
    }

    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port);

        if ($socket !== false) {
            fclose($socket);
            break;
        }

        usleep(100000);
    }

    $analystSession = plant_session076(
        $store,
        $analystId,
        'analyst',
    );

    /*
     * ----------------------------------------------------------------------
     * 1. Mass-assignment protection
     * ----------------------------------------------------------------------
     */

    $forbiddenFields = [
        'id' => $incidentId,
        'incident_uuid' => 'attacker-controlled',
        'incident_number' => 'ATTACK-076',
        'created_at' => '2000-01-01 00:00:00',
        'updated_at' => '2000-01-01 00:00:00',
        'detected_at' => '2000-01-01 00:00:00',
        'acknowledged_at' => '2000-01-01 00:00:00',
        'contained_at' => '2000-01-01 00:00:00',
        'resolved_at' => '2000-01-01 00:00:00',
        'closed_at' => '2000-01-01 00:00:00',
        'user_id' => $viewerId,
        'role' => 'admin',
        'actor_id' => $viewerId,
    ];

    foreach ($forbiddenFields as $field => $value) {
        $response = http076(
            $base,
            'PATCH',
            "/index.php/api/incidents/$incidentId",
            $analystSession,
            [$field => $value],
        );

        check076(
            "mass assignment: {$field} rejected",
            $response[0] === 422,
            "HTTP {$response[0]}",
        );

        $after = incident076($pdo, $incidentId);

        check076(
            "mass assignment: {$field} caused no modification",
            $after === $before,
        );
    }

    /*
     * ----------------------------------------------------------------------
     * 2. Mixed allowed + forbidden field
     * ----------------------------------------------------------------------
     */

    $response = http076(
        $base,
        'PATCH',
        "/index.php/api/incidents/$incidentId",
        $analystSession,
        [
            'title' => 'MUST NOT BE APPLIED',
            'role' => 'admin',
        ],
    );

    check076(
        'mixed allowed + forbidden fields rejected',
        $response[0] === 422,
        "HTTP {$response[0]}",
    );

    check076(
        'mixed payload produces no partial update',
        incident076($pdo, $incidentId) === $before,
    );

    /*
     * ----------------------------------------------------------------------
     * 3. Type confusion / invalid values
     * ----------------------------------------------------------------------
     */

    $invalidPayloads = [
        ['title' => []],
        ['title' => 123],
        ['title' => null],

        ['description' => []],
        ['description' => 123],

        ['severity' => '4'],
        ['severity' => []],
        ['severity' => 0],
        ['severity' => 5],

        ['status' => []],
        ['status' => 'invalid-status'],

        ['priority' => 123],
        ['priority' => []],
        ['priority' => 'urgent'],

        ['assigned_to' => '1'],
        ['assigned_to' => []],
        ['assigned_to' => 0],
        ['assigned_to' => -1],
        ['assigned_to' => 999999999],

        ['resolution' => []],
        ['resolution' => 123],
    ];

    foreach ($invalidPayloads as $payload) {
        $response = http076(
            $base,
            'PATCH',
            "/index.php/api/incidents/$incidentId",
            $analystSession,
            $payload,
        );

        check076(
            'invalid type/value rejected with 422',
            $response[0] === 422,
            'Payload: ' . json_encode($payload),
        );

        check076(
            'invalid type/value caused no modification',
            incident076($pdo, $incidentId) === $before,
        );
    }

    /*
     * ----------------------------------------------------------------------
     * 4. Empty and unknown-only payloads
     * ----------------------------------------------------------------------
     */

    foreach (
        [
            [],
            ['unknown_field' => 'x'],
            ['role' => 'admin'],
            ['actor_id' => $viewerId],
        ] as $payload
    ) {
        $response = http076(
            $base,
            'PATCH',
            "/index.php/api/incidents/$incidentId",
            $analystSession,
            $payload,
        );

        check076(
            'empty/unknown-only payload rejected',
            $response[0] === 422,
            'Payload: ' . json_encode($payload),
        );

        check076(
            'empty/unknown-only payload caused no modification',
            incident076($pdo, $incidentId) === $before,
        );
    }

    /*
     * ----------------------------------------------------------------------
     * 5. SQL injection must remain data
     * ----------------------------------------------------------------------
     */

    $sqlPayloads = [
        ['title' => "APP076'; DROP TABLE incidents; --"],
        ['description' => "' OR 1=1 --"],
        ['resolution' => '"; UPDATE users SET role="admin"; --'],
    ];

    foreach ($sqlPayloads as $payload) {
        $response = http076(
            $base,
            'PATCH',
            "/index.php/api/incidents/$incidentId",
            $analystSession,
            $payload,
        );

        check076(
            'SQL injection payload does not produce server error',
            in_array($response[0], [200, 422], true),
            "HTTP {$response[0]}",
        );

        check076(
            'incidents table remains intact',
            (int) $pdo->query('SELECT COUNT(*) FROM incidents')->fetchColumn() >= 1,
        );

        check076(
            'users table remains intact',
            (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 1,
        );
    }

    /*
     * ----------------------------------------------------------------------
     * 6. Positive control: legitimate fields
     * ----------------------------------------------------------------------
     */

    $response = http076(
        $base,
        'PATCH',
        "/index.php/api/incidents/$incidentId",
        $analystSession,
        [
            'title' => 'APP076 legitimate update',
            'description' => 'Validated description',
            'severity' => 4,
            'priority' => 'high',
            'assigned_to' => $analystId,
            'resolution' => null,
        ],
    );

    check076(
        'legitimate mutable fields accepted',
        $response[0] === 200,
        "HTTP {$response[0]}",
    );

    $after = incident076($pdo, $incidentId);

    check076(
        'title legitimate update persisted',
        $after['title'] === 'APP076 legitimate update',
    );

    check076(
        'description legitimate update persisted',
        $after['description'] === 'Validated description',
    );

    check076(
        'severity legitimate update persisted',
        (int) $after['severity'] === 4,
    );

    check076(
        'priority legitimate update persisted',
        $after['priority'] === 'high',
    );

    check076(
        'assigned_to legitimate update persisted',
        (int) $after['assigned_to'] === $analystId,
    );

    /*
     * ----------------------------------------------------------------------
     * 7. Valid status transition
     * ----------------------------------------------------------------------
     */

    $response = http076(
        $base,
        'PATCH',
        "/index.php/api/incidents/$incidentId",
        $analystSession,
        [
            'status' => 'acknowledged',
        ],
    );

    check076(
        'valid status transition accepted',
        $response[0] === 200,
        "HTTP {$response[0]}",
    );

    $after = incident076($pdo, $incidentId);

    check076(
        'valid status transition persisted',
        $after['status'] === 'acknowledged',
    );

    /*
     * ----------------------------------------------------------------------
     * 8. Actor identity cannot be controlled by client
     * ----------------------------------------------------------------------
     */

    $response = http076(
        $base,
        'PATCH',
        "/index.php/api/incidents/$incidentId",
        $analystSession,
        [
            'description' => 'ATTACKER CONTROLLED ACTOR',
            'actor_id' => $viewerId,
            'user_id' => $viewerId,
            'role' => 'admin',
        ],
    );

    check076(
        'client cannot control actor identity',
        $response[0] === 422,
        "HTTP {$response[0]}",
    );

    /*
     * ----------------------------------------------------------------------
     * Final structural check
     * ----------------------------------------------------------------------
     */

    check076(
        'target incident still exists exactly once',
        (int) $pdo
            ->query("SELECT COUNT(*) FROM incidents WHERE id = $incidentId")
            ->fetchColumn() === 1,
    );

} catch (Throwable $exception) {
    check076(
        'unexpected test exception',
        false,
        get_class($exception) . ': ' . $exception->getMessage(),
    );
} finally {
    if (is_resource($process)) {
        proc_terminate($process);

        foreach ($serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);
    }

    if ($incidentId !== null) {
        $statement = $pdo->prepare(
            'DELETE FROM incidents WHERE id = :id'
        );
        $statement->execute(['id' => $incidentId]);
    }

    if ($store !== null && is_dir($store)) {
        foreach (glob($store . '/sess_*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($store);
    }

    /*
     * tk_cleanup() restores the database state for all rows created
     * through the shared test helpers.
     */
    tk_cleanup();
}

/*
 * --------------------------------------------------------------------------
 * Result
 * --------------------------------------------------------------------------
 */

$passed = 0;
$failed = 0;

foreach ($results as $result) {
    if ($result['ok']) {
        $passed++;
        echo "PASS {$result['label']}\n";
    } else {
        $failed++;
        echo "FAIL {$result['label']}";

        if ($result['detail'] !== '') {
            echo " — {$result['detail']}";
        }

        echo "\n";
    }
}

$total = count($results);

echo "\n{$passed}/{$total} checks passed\n";

if ($failed > 0) {
    echo "APP-07.6 INPUT VALIDATION / MASS ASSIGNMENT: FAIL\n";
    exit(1);
}

echo "APP-07.6 INPUT VALIDATION / MASS ASSIGNMENT: PASS\n";
exit(0);
