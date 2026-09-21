<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Exceptions\AuthorizationException;
use CyberGuard\Campus\Models\Incident;
use CyberGuard\Campus\Repositories\IncidentRepository;
use InvalidArgumentException;
use RuntimeException;

final class IncidentService
{
    private const ALLOWED_FIELDS = [
        'title',
        'description',
        'severity',
        'status',
        'priority',
        'assigned_to',
        'resolution',
    ];

    /**
     * Incident workflow: the only status changes the backend accepts, in response order.
     * The current status always comes from the stored incident, never from the client.
     * 'closed' is final. Requesting the status an incident already has is a no-op, not a transition.
     */
    private const STATUS_TRANSITIONS = [
        'open' => ['acknowledged'],
        'acknowledged' => ['investigating'],
        'investigating' => ['contained'],
        'contained' => ['resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    /** Lifecycle column stamped (database time) when an incident enters a status. */
    private const LIFECYCLE_TIMESTAMPS = [
        'acknowledged' => 'acknowledged_at',
        'contained' => 'contained_at',
        'resolved' => 'resolved_at',
        'closed' => 'closed_at',
    ];

    /** incident_history.action for a status transition (previous/new status carry the detail). */
    private const HISTORY_STATUS_CHANGED = 'status_changed';

    /**
     * Statuses that only some roles may set. Who may PATCH at all (analyst, admin) is enforced
     * once, on the route (AuthorizationMiddleware); only the extra rule per status lives here.
     */
    private const STATUS_PERMISSIONS = [
        'closed' => ['admin'],
    ];

    public function __construct(
        private readonly IncidentRepository $incidentRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIncidents(): array
    {
        $incidents = $this->incidentRepository->findAll();

        return array_map(
            static fn (Incident $incident): array => $incident->toArray(),
            $incidents,
        );
    }

    /**
     * One incident with its links and its history (oldest first). Read only: no lock, no write.
     *
     * @return array{incident: array<string, mixed>, history: list<array<string, mixed>>}
     */
    public function getIncidentDetail(int $incidentId): array
    {
        $incident = $this->incidentRepository->findDetailById($incidentId);

        if ($incident === null) {
            throw new RuntimeException(
                'Incident not found.',
                404,
            );
        }

        return [
            'incident' => $incident,
            'history' => $this->incidentRepository->findHistory($incidentId),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $actorId Authenticated user performing the change, from the session only
     *                     (never from the payload). Recorded as incident_history.user_id.
     * @param string $actorRole Role of that user, from the session only (never from the payload).
     * @return array<string, mixed>
     */
    public function updateIncident(int $incidentId, array $payload, int $actorId, string $actorRole): array
    {
        $allowedFields = array_flip(self::ALLOWED_FIELDS);
        $unknownFields = array_diff_key($payload, $allowedFields);

        if ($unknownFields !== []) {
            throw new InvalidArgumentException(
                'Unknown field(s): ' . implode(', ', array_keys($unknownFields)),
                422,
            );
        }

        $normalized = [];

        if (array_key_exists('title', $payload)) {
            $title = $payload['title'];

            if (!is_string($title) || trim($title) === '') {
                throw new InvalidArgumentException(
                    'Title must be a non-empty string.',
                    422,
                );
            }

            $normalized['title'] = trim($title);
        }

        if (array_key_exists('description', $payload)) {
            $description = $payload['description'];

            if ($description !== null && !is_string($description)) {
                throw new InvalidArgumentException(
                    'Description must be a string or null.',
                    422,
                );
            }

            $normalized['description'] = $description;
        }

        if (array_key_exists('severity', $payload)) {
            $severity = $payload['severity'];

            if (!is_int($severity) || $severity < 1 || $severity > 4) {
                throw new InvalidArgumentException(
                    'Severity must be an integer between 1 and 4.',
                    422,
                );
            }

            $normalized['severity'] = $severity;
        }

        if (array_key_exists('status', $payload)) {
            $status = $payload['status'];

            if (!is_string($status) || !in_array($status, array_keys(self::STATUS_TRANSITIONS), true)) {
                throw new InvalidArgumentException(
                    'Status must be one of: open, acknowledged, investigating, contained, resolved, closed.',
                    422,
                );
            }

            $normalized['status'] = $status;
        }

        if (array_key_exists('priority', $payload)) {
            $priority = $payload['priority'];

            if (!is_string($priority) || !in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
                throw new InvalidArgumentException(
                    'Priority must be one of: low, medium, high, critical.',
                    422,
                );
            }

            $normalized['priority'] = $priority;
        }

        if (array_key_exists('assigned_to', $payload)) {
            $assignedTo = $payload['assigned_to'];

            if ($assignedTo !== null && (!is_int($assignedTo) || $assignedTo <= 0)) {
                throw new InvalidArgumentException(
                    'Assigned user must be null or a valid user id.',
                    422,
                );
            }

            if ($assignedTo !== null && !$this->incidentRepository->userExists($assignedTo)) {
                throw new InvalidArgumentException(
                    'Assigned user does not exist.',
                    422,
                );
            }

            $normalized['assigned_to'] = $assignedTo;
        }

        if (array_key_exists('resolution', $payload)) {
            $resolution = $payload['resolution'];

            if ($resolution !== null && !is_string($resolution)) {
                throw new InvalidArgumentException(
                    'Resolution must be a string or null.',
                    422,
                );
            }

            $normalized['resolution'] = $resolution;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'At least one valid field must be provided.',
                422,
            );
        }

        // One transaction from the locked read to the last write (incident, lifecycle timestamp,
        // history): the status the transition is validated against cannot change underneath
        // us, and nothing is half written.
        $ownsTransaction = $this->incidentRepository->beginTransaction();

        try {
            $incident = $this->incidentRepository->findByIdForUpdate($incidentId);

            if ($incident === null) {
                throw new RuntimeException(
                    'Incident not found.',
                    404,
                );
            }

            // Set only for a real, validated status change: [stored status, new status].
            $transition = null;

            if (array_key_exists('status', $normalized)) {
                $currentStatus = $incident->status();

                if ($normalized['status'] === $currentStatus) {
                    // Same status: nothing changes and no transition is recorded.
                    unset($normalized['status']);
                } else {
                    // Permission first (403), then the workflow (422), both on the locked row.
                    $this->assertStatusPermitted($normalized['status'], $actorRole);
                    $this->assertTransitionAllowed($currentStatus, $normalized['status']);
                    $transition = [$currentStatus, $normalized['status']];
                }
            }

            if ($normalized === []) {
                // The status was the only field and it was already set: the incident stays as it is.
                $result = $incident->toArray();
            } else {
                $result = $this->incidentRepository->update($incidentId, $normalized)->toArray();
            }

            if ($transition !== null) {
                [$previousStatus, $newStatus] = $transition;
                $lifecycleColumn = self::LIFECYCLE_TIMESTAMPS[$newStatus] ?? null;

                if ($lifecycleColumn !== null) {
                    $this->incidentRepository->markLifecycleTimestamp($incidentId, $lifecycleColumn);
                }

                // Exactly one history row per transition. The actor comes from the session
                // (through the controller); both statuses come from the locked row and the
                // validated request. An assignment in the same PATCH is recorded alongside.
                $previousAssignee = $incident->assignedTo();
                $this->incidentRepository->insertHistory(
                    incidentId: $incidentId,
                    userId: $actorId,
                    action: self::HISTORY_STATUS_CHANGED,
                    previousStatus: $previousStatus,
                    newStatus: $newStatus,
                    previousAssignee: $previousAssignee,
                    newAssignee: array_key_exists('assigned_to', $normalized) ? $normalized['assigned_to'] : $previousAssignee,
                );

                if ($lifecycleColumn !== null) {
                    // Return the stored lifecycle timestamp as well.
                    $reloaded = $this->incidentRepository->findById($incidentId);

                    if ($reloaded === null) {
                        throw new RuntimeException(
                            'Incident could not be reloaded after update.',
                            500,
                        );
                    }

                    $result = $reloaded->toArray();
                }
            }

            if ($ownsTransaction) {
                $this->incidentRepository->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $this->incidentRepository->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Role check for a status change: closing an incident is reserved to administrators.
     */
    private function assertStatusPermitted(string $requestedStatus, string $actorRole): void
    {
        $allowedRoles = self::STATUS_PERMISSIONS[$requestedStatus] ?? null;

        if ($allowedRoles !== null && !in_array($actorRole, $allowedRoles, true)) {
            throw new AuthorizationException('Insufficient permissions.');
        }
    }

    /**
     * Enforces the incident workflow. `$currentStatus` is the stored status.
     */
    private function assertTransitionAllowed(string $currentStatus, string $requestedStatus): void
    {
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        if (in_array($requestedStatus, $allowed, true)) {
            return;
        }

        throw new InvalidArgumentException(
            $allowed === []
                ? sprintf('Status cannot change: an incident that is %s is final.', $currentStatus)
                : sprintf(
                    'Status cannot change from %s to %s. The next status can only be: %s.',
                    $currentStatus,
                    $requestedStatus,
                    implode(', ', $allowed),
                ),
            422,
        );
    }
}
