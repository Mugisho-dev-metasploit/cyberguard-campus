<?php

declare(strict_types=1);

/**
 * APP-06.8 — incident workflow at service level (APP-06.2 → APP-06.5 guarantees).
 *
 * Real IncidentService + IncidentRepository on the configured database: state machine
 * (36 combinations × analyst/admin), role rule for closing, history, lifecycle timestamps,
 * rejected client fields and values, atomicity (failure at UPDATE, timestamp, history),
 * repository column allow-list, read-only detail. Viewer refusal is enforced on the route and
 * is covered by IncidentApiTest.php.
 *
 * Run: /opt/lampp/bin/php backend/tests/IncidentWorkflowTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Exceptions\AuthorizationException;
use CyberGuard\Campus\Repositories\IncidentRepository;
use CyberGuard\Campus\Services\IncidentService;

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$service = new IncidentService(new IncidentRepository(tk_pdo()));
$failing = null;

/** @return array{0: string, 1: ?Throwable} outcome ('accepted' | 'no-op' | 'forbidden' | 'refused' | 'error') and exception */
function attempt(IncidentService $service, int $id, array $payload, int $actor, string $role): array
{
    $before = tk_row($id);

    try {
        $service->updateIncident($id, $payload, $actor, $role);

        return [tk_row($id) === $before ? 'no-op' : 'accepted', null];
    } catch (AuthorizationException $e) {
        return ['forbidden', $e];
    } catch (InvalidArgumentException $e) {
        return ['refused', $e];
    } catch (Throwable $e) {
        return ['error', $e];
    }
}

try {
    $admin = tk_user('admin');
    $analyst = tk_user('analyst');
    $assignee = tk_user('analyst', 'Assignee');

    /* 1. State machine: 36 combinations, per role -------------------------------------------- */
    foreach (['admin' => $admin, 'analyst' => $analyst] as $role => $actor) {
        $tally = [];
        $wrong = [];

        foreach (TK_STATUSES as $from) {
            foreach (TK_STATUSES as $to) {
                $id = tk_incident($from);
                $initial = tk_row($id);

                $expected = match (true) {
                    $from === $to => 'no-op',
                    $to === 'closed' && $role !== 'admin' => 'forbidden',   // permission is checked before the workflow
                    (TK_NEXT[$from] ?? null) === $to => 'accepted',
                    default => 'refused',
                };

                [$got, $error] = attempt($service, $id, ['status' => $to], $actor, $role);
                $after = tk_row($id);
                $history = tk_history($id);

                $sideEffects = $expected === 'accepted'
                    ? $after['status'] === $to && count($history) === 1 && tk_stamped($after) === (isset(TK_STAMP[$to]) ? [TK_STAMP[$to]] : [])
                        && $after['updated_at'] !== $initial['updated_at']
                    : $after === $initial && $history === [];   // same status, same updated_at, no history

                $codeOk = match ($got) {
                    'refused' => $error->getCode() === 422,
                    default => true,
                };

                $tally[$got] = ($tally[$got] ?? 0) + 1;

                if ($got !== $expected || !$sideEffects || !$codeOk) {
                    $wrong[] = "{$from}→{$to}: expected {$expected}, got {$got}" . ($sideEffects ? '' : ' (side effects)');
                }
            }
        }

        ksort($tally);
        $expectedTally = $role === 'admin'
            ? ['accepted' => 5, 'no-op' => 6, 'refused' => 25]
            : ['accepted' => 4, 'forbidden' => 5, 'no-op' => 6, 'refused' => 21];
        ksort($expectedTally);
        check("$role: 36 combinations " . json_encode($expectedTally) . '; refusals and no-ops keep status, updated_at, no history',
            $tally === $expectedTally && $wrong === [], json_encode($tally) . ' ' . implode('; ', array_slice($wrong, 0, 3)));
    }

    /* 2. Permissions: analyst cannot close, admin can; nothing written on refusal ------------ */
    $id = tk_incident('resolved');
    $initial = tk_row($id);
    [$got] = attempt($service, $id, ['status' => 'closed', 'title' => 'must not be saved'], $analyst, 'analyst');
    check('analyst resolved → closed (with another field): 403, nothing written, no history',
        $got === 'forbidden' && tk_row($id) === $initial && tk_history($id) === []);
    [$got] = attempt($service, $id, ['status' => 'closed'], $admin, 'admin');
    check('admin resolved → closed: accepted, closed_at set, 1 history row by the admin',
        $got === 'accepted' && tk_row($id)['closed_at'] !== null && count(tk_history($id)) === 1 && (int) tk_history($id)[0]['user_id'] === $admin);

    /* 3. History content ------------------------------------------------------------------------ */
    $id = tk_incident('open', ['assigned_to' => $analyst]);
    $service->updateIncident($id, ['status' => 'acknowledged', 'assigned_to' => $assignee], $admin, 'admin');
    $h = tk_history($id);
    check('transition + reassignment: one row with status_changed, previous/new status, actor, previous/new assignee, created_at',
        count($h) === 1 && $h[0]['action'] === 'status_changed' && $h[0]['previous_status'] === 'open' && $h[0]['new_status'] === 'acknowledged'
        && (int) $h[0]['user_id'] === $admin && (int) $h[0]['previous_assignee'] === $analyst && (int) $h[0]['new_assignee'] === $assignee
        && $h[0]['created_at'] !== null && $h[0]['comment'] === null, json_encode($h));

    $service->updateIncident($id, ['status' => 'investigating'], $analyst, 'analyst');
    $h = tk_history($id);
    check('transition without reassignment: previous_assignee = new_assignee = current owner; actor = this caller',
        count($h) === 2 && (int) $h[1]['previous_assignee'] === $assignee && (int) $h[1]['new_assignee'] === $assignee && (int) $h[1]['user_id'] === $analyst);

    $service->updateIncident($id, ['title' => 'field edit only', 'assigned_to' => $admin], $analyst, 'analyst');
    check('edit without status change (even a reassignment): no status_changed invented', count(tk_history($id)) === 2);

    $service->updateIncident($id, ['status' => 'investigating'], $admin, 'admin');
    check('no-op status: no history row', count(tk_history($id)) === 2);

    /* 4. Lifecycle timestamps: full cycle, database time, correct column per transition ------- */
    $id = tk_incident('open');
    $expectations = [];
    foreach (['acknowledged', 'investigating', 'contained', 'resolved', 'closed'] as $next) {
        $service->updateIncident($id, ['status' => $next], $admin, 'admin');
        $expectations[] = tk_stamped(tk_row($id));
    }
    check('timestamps: acknowledged_at, (none for investigating), contained_at, resolved_at, closed_at — in that order', $expectations === [
        ['acknowledged_at'], ['acknowledged_at'], ['acknowledged_at', 'contained_at'],
        ['acknowledged_at', 'contained_at', 'resolved_at'], TK_LIFECYCLE,
    ], json_encode($expectations));
    $r = tk_row($id);
    check('timestamps come from the database clock (within a minute of NOW(), microsecond precision)',
        abs(strtotime((string) $r['closed_at']) - strtotime((string) tk_pdo()->query('SELECT NOW()')->fetchColumn())) < 60
        && str_contains((string) $r['closed_at'], '.'));
    check('full cycle: exactly 5 history rows, in order',
        array_map(static fn (array $x): string => $x['previous_status'] . '→' . $x['new_status'], tk_history($id))
        === ['open→acknowledged', 'acknowledged→investigating', 'investigating→contained', 'contained→resolved', 'resolved→closed']);

    /* 5. Client-controlled fields and values are rejected ------------------------------------- */
    $forbidden = ['user_id' => 999, 'role' => 'admin', 'previous_status' => 'resolved', 'acknowledged_at' => '2020-01-01 00:00:00',
        'contained_at' => '2020-01-01 00:00:00', 'resolved_at' => '2020-01-01 00:00:00', 'closed_at' => '2020-01-01 00:00:00',
        'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00', 'id' => 1, 'incident_id' => 1];
    $leaks = [];
    foreach ($forbidden as $field => $value) {
        $id = tk_incident('open');
        $initial = tk_row($id);
        [$got, $e] = attempt($service, $id, ['status' => 'acknowledged', $field => $value], $analyst, 'analyst');
        if (!($got === 'refused' && $e->getCode() === 422 && str_contains($e->getMessage(), 'Unknown field') && tk_row($id) === $initial && tk_history($id) === [])) {
            $leaks[] = $field;
        }
    }
    check('forbidden fields (' . implode(', ', array_keys($forbidden)) . ') → 422 Unknown field, nothing written', $leaks === [], implode(', ', $leaks));

    $invalid = [
        ['status' => 'archived'], ['status' => 1], ['status' => null],
        ['severity' => 0], ['severity' => 5], ['severity' => '3'],
        ['priority' => 'urgent'], ['priority' => null],
        ['assigned_to' => 0], ['assigned_to' => -1], ['assigned_to' => '5'], ['assigned_to' => 999999999],
        ['title' => ''], ['title' => '   '], ['title' => 5], [],
    ];
    $accepted = [];
    foreach ($invalid as $payload) {
        $id = tk_incident('open');
        $initial = tk_row($id);
        [$got, $e] = attempt($service, $id, $payload, $admin, 'admin');
        if (!($got === 'refused' && $e->getCode() === 422 && tk_row($id) === $initial)) {
            $accepted[] = json_encode($payload);
        }
    }
    check('invalid values (status, severity, priority, assigned_to, title, empty payload) → 422, nothing written', $accepted === [], implode(' ', $accepted));

    /* 6. Atomicity: a failure at any write step rolls everything back ------------------------- */
    $failing = tk_connect(TkThrowingPdo::class);
    $failingService = new IncidentService(new IncidentRepository($failing));
    foreach ([
        'incident UPDATE' => 'updated_at = CURRENT_TIMESTAMP(6) WHERE id',
        'lifecycle timestamp' => 'closed_at = CURRENT_TIMESTAMP(6)',
        'history INSERT' => 'INSERT INTO incident_history',
    ] as $step => $needle) {
        $id = tk_incident('resolved', ['assigned_to' => $analyst]);
        $initial = tk_row($id);
        $failing->failOn = $needle;
        [$got, $e] = attempt($failingService, $id, ['status' => 'closed', 'title' => 'rolled back', 'description' => 'x', 'severity' => 4,
            'priority' => 'critical', 'assigned_to' => $assignee], $admin, 'admin');
        $failing->failOn = '';
        check("failure at $step → complete rollback (status, title, description, severity, priority, assigned_to, timestamps, history)",
            $got === 'error' && $e instanceof PDOException && tk_row($id) === $initial && tk_history($id) === [] && !$failing->inTransaction(),
            $got . ' ' . json_encode(array_diff_assoc(tk_row($id), $initial)));
    }
    $failing = null;

    $id = tk_incident('open');
    $initial = tk_row($id);
    [$got, $e] = attempt($service, $id, ['status' => 'acknowledged', 'title' => str_repeat('x', 300)], $admin, 'admin');
    check('real database error during UPDATE (value too long) → rollback, nothing written', $got === 'error' && tk_row($id) === $initial && tk_history($id) === []);

    $id = tk_incident('open');
    $initial = tk_row($id);
    [$got] = attempt($service, $id, ['status' => 'acknowledged', 'priority' => 'high'], 999999999, 'admin');
    check('history rejected by the users foreign key (unknown actor) → incident rolled back too', $got === 'error' && tk_row($id) === $initial && tk_history($id) === []);
    check('no transaction left open on the shared connection', !tk_pdo()->inTransaction());

    /* 7. Repository column allow-list: column names never come from input ------------------------ */
    $repo = new IncidentRepository(tk_pdo());
    $id = tk_incident('open');
    $initial = tk_row($id);
    $blocked = 0;
    foreach (['status = \'closed\', title' => 'x', 'title = (SELECT password_hash FROM users LIMIT 1), title' => 'x', 'updated_at' => '2020-01-01'] as $column => $value) {
        try {
            $repo->update($id, [$column => $value]);
        } catch (RuntimeException) {
            $blocked++;
        }
    }
    try {
        $repo->markLifecycleTimestamp($id, 'closed_at = NOW(), status');
    } catch (RuntimeException) {
        $blocked++;
    }
    check('repository refuses non-allow-listed columns (update and lifecycle stamp), nothing written', $blocked === 4 && tk_row($id) === $initial);

    /* 8. Read path is read-only: no transaction, no lock, no write statement ------------------- */
    $recording = tk_connect(TkRecordingPdo::class);
    $id = tk_incident('investigating');
    $readService = new IncidentService(new IncidentRepository($recording));
    $snapshot = tk_counts();
    $readService->getIncidentDetail($id);
    $readService->listIncidents();
    $writes = array_filter($recording->statements, static fn (string $q): bool => (bool) preg_match('/\b(INSERT|UPDATE|DELETE|FOR UPDATE)\b/i', $q));
    check('GET detail/list: no transaction, no FOR UPDATE, no INSERT/UPDATE/DELETE, 3 statements, counts unchanged',
        $recording->transactions === 0 && $writes === [] && count($recording->statements) === 3 && tk_counts() === $snapshot,
        json_encode(['tx' => $recording->transactions, 'n' => count($recording->statements), 'writes' => array_values($writes)]));
    $recording = null;
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    $failing = null;
    $recording = null;
    tk_cleanup();
}

tk_finish($before);
