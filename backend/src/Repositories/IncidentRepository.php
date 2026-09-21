<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use CyberGuard\Campus\Models\Incident;
use PDO;
use RuntimeException;

final class IncidentRepository
{
    private const ALLOWED_UPDATE_FIELDS = [
        'title',
        'description',
        'severity',
        'status',
        'priority',
        'assigned_to',
        'resolution',
    ];

    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @return list<Incident>
     */
    public function findAll(): array
    {
        $sql = <<<'SQL'
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
            ORDER BY detected_at DESC, id DESC
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $rows = $statement->fetchAll();

        if ($rows === false) {
            throw new RuntimeException(
                'Unable to load incidents.'
            );
        }

        return array_map(
            fn (array $row): Incident => $this->mapToIncident($row),
            $rows,
        );
    }

    /**
     * Opens a transaction unless one is already running on this connection.
     * Returns true when this call started it, so only the owner commits or rolls back.
     */
    public function beginTransaction(): bool
    {
        if ($this->connection->inTransaction()) {
            return false;
        }

        $this->connection->beginTransaction();

        return true;
    }

    public function commit(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    /**
     * Same read as findById(), with a row lock (FOR UPDATE): a concurrent update of this
     * incident waits until the current transaction ends. Must run inside a transaction,
     * on this same connection, before the status is validated and written.
     */
    public function findByIdForUpdate(int $id): ?Incident
    {
        $sql = <<<'SQL'
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
            FOR UPDATE
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapToIncident($row);
    }

    public function findById(int $id): ?Incident
    {
        $sql = <<<'SQL'
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
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapToIncident($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $incidentId, array $fields): Incident
    {
        if ($fields === []) {
            throw new RuntimeException(
                'No incident fields were provided for update.',
                422,
            );
        }

        $invalidFields = [];
        foreach (array_keys($fields) as $field) {
            if (!in_array($field, self::ALLOWED_UPDATE_FIELDS, true)) {
                $invalidFields[] = $field;
            }
        }

        if ($invalidFields !== []) {
            throw new RuntimeException(
                'Invalid incident field(s): ' . implode(', ', $invalidFields),
                422,
            );
        }

        $columns = [];

        foreach ($fields as $field => $value) {
            $columns[] = $field . ' = :' . $field;
        }

        $sql = 'UPDATE incidents SET ' . implode(', ', $columns) . ', updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id';
        $statement = $this->connection->prepare($sql);

        $parameters = $fields;
        $parameters['id'] = $incidentId;

        $statement->execute($parameters);

        $updatedIncident = $this->findById($incidentId);

        if ($updatedIncident === null) {
            throw new RuntimeException(
                'Incident could not be reloaded after update.',
                500,
            );
        }

        return $updatedIncident;
    }

    /** Lifecycle columns the backend may stamp. Never taken from the client. */
    private const LIFECYCLE_COLUMNS = [
        'acknowledged_at',
        'contained_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * Stamps one lifecycle column with the database time. Part of the caller's transaction.
     */
    public function markLifecycleTimestamp(int $incidentId, string $column): void
    {
        if (!in_array($column, self::LIFECYCLE_COLUMNS, true)) {
            throw new RuntimeException(
                'Invalid lifecycle column.',
                500,
            );
        }

        $statement = $this->connection->prepare(
            'UPDATE incidents SET ' . $column . ' = CURRENT_TIMESTAMP(6) WHERE id = :id'
        );
        $statement->execute([
            'id' => $incidentId,
        ]);
    }

    /**
     * Appends one row to incident_history (created_at: database default). Part of the
     * caller's transaction: if it fails, the incident change is rolled back with it.
     */
    public function insertHistory(
        int $incidentId,
        int $userId,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?int $previousAssignee,
        ?int $newAssignee,
    ): void {
        $sql = <<<'SQL'
            INSERT INTO incident_history (
                incident_id,
                user_id,
                action,
                previous_status,
                new_status,
                previous_assignee,
                new_assignee,
                comment,
                metadata
            ) VALUES (
                :incident_id,
                :user_id,
                :action,
                :previous_status,
                :new_status,
                :previous_assignee,
                :new_assignee,
                NULL,
                NULL
            )
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'incident_id' => $incidentId,
            'user_id' => $userId,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'previous_assignee' => $previousAssignee,
            'new_assignee' => $newAssignee,
        ]);
    }

    /**
     * Read model for GET /api/incidents/{id}: the incident exactly as listed, plus its links
     * (assignee, alert, device). Explicit columns only: no metadata, no credential data.
     * Plain read, no lock. One query.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailById(int $id): ?array
    {
        $sql = <<<'SQL'
            SELECT
                i.id,
                i.incident_uuid,
                i.incident_number,
                i.title,
                i.description,
                i.severity,
                i.status,
                i.priority,
                i.assigned_to,
                i.detected_at,
                i.acknowledged_at,
                i.contained_at,
                i.resolved_at,
                i.closed_at,
                i.created_at,
                i.updated_at,
                i.alert_id,
                i.device_id,
                i.resolution,
                assignee.id AS assignee_id,
                assignee.username AS assignee_username,
                assignee.first_name AS assignee_first_name,
                assignee.last_name AS assignee_last_name,
                assignee.role AS assignee_role,
                alert.id AS alert_ref_id,
                alert.alert_uuid AS alert_uuid,
                alert.title AS alert_title,
                alert.severity AS alert_severity,
                alert.status AS alert_status,
                alert.detected_at AS alert_detected_at,
                device.id AS device_ref_id,
                device.uuid AS device_uuid,
                device.hostname AS device_hostname,
                device.ip_address AS device_ip_address,
                device.device_type AS device_type,
                device.environment AS device_environment,
                device.status AS device_status
            FROM incidents i
            LEFT JOIN users assignee ON assignee.id = i.assigned_to
            LEFT JOIN alerts alert ON alert.id = i.alert_id
            LEFT JOIN devices device ON device.id = i.device_id
            WHERE i.id = :id
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $incident = $this->mapToIncident($row)->toArray();

        $incident['alert_id'] = $row['alert_id'] !== null ? (int) $row['alert_id'] : null;
        $incident['device_id'] = $row['device_id'] !== null ? (int) $row['device_id'] : null;
        $incident['resolution'] = $row['resolution'] !== null ? (string) $row['resolution'] : null;
        $incident['assignee'] = $this->mapPublicUser($row, 'assignee_');
        $incident['alert'] = $row['alert_ref_id'] === null ? null : [
            'id' => (int) $row['alert_ref_id'],
            'alert_uuid' => (string) $row['alert_uuid'],
            'title' => (string) $row['alert_title'],
            'severity' => (int) $row['alert_severity'],
            'status' => (string) $row['alert_status'],
            'detected_at' => (string) $row['alert_detected_at'],
        ];
        $incident['device'] = $row['device_ref_id'] === null ? null : [
            'id' => (int) $row['device_ref_id'],
            'uuid' => (string) $row['device_uuid'],
            'hostname' => (string) $row['device_hostname'],
            'ip_address' => $row['device_ip_address'] !== null ? (string) $row['device_ip_address'] : null,
            'device_type' => (string) $row['device_type'],
            'environment' => (string) $row['device_environment'],
            'status' => (string) $row['device_status'],
        ];

        return $incident;
    }

    /**
     * History of one incident, oldest first (created_at, then id for identical timestamps).
     * The actor and both assignees are joined (public fields only); a user removed from the
     * database leaves NULL there (ON DELETE SET NULL), returned as null. One query.
     *
     * @return list<array<string, mixed>>
     */
    public function findHistory(int $incidentId): array
    {
        $sql = <<<'SQL'
            SELECT
                h.id,
                h.action,
                h.previous_status,
                h.new_status,
                h.user_id,
                h.previous_assignee,
                h.new_assignee,
                h.comment,
                h.created_at,
                actor.id AS actor_id,
                actor.username AS actor_username,
                actor.first_name AS actor_first_name,
                actor.last_name AS actor_last_name,
                actor.role AS actor_role,
                previous_user.id AS previous_user_id,
                previous_user.username AS previous_user_username,
                previous_user.first_name AS previous_user_first_name,
                previous_user.last_name AS previous_user_last_name,
                previous_user.role AS previous_user_role,
                new_user.id AS new_user_id,
                new_user.username AS new_user_username,
                new_user.first_name AS new_user_first_name,
                new_user.last_name AS new_user_last_name,
                new_user.role AS new_user_role
            FROM incident_history h
            LEFT JOIN users actor ON actor.id = h.user_id
            LEFT JOIN users previous_user ON previous_user.id = h.previous_assignee
            LEFT JOIN users new_user ON new_user.id = h.new_assignee
            WHERE h.incident_id = :incident_id
            ORDER BY h.created_at ASC, h.id ASC
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'incident_id' => $incidentId,
        ]);

        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'previous_status' => $row['previous_status'] !== null ? (string) $row['previous_status'] : null,
                'new_status' => $row['new_status'] !== null ? (string) $row['new_status'] : null,
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'actor' => $this->mapPublicUser($row, 'actor_'),
                'previous_assignee' => $row['previous_assignee'] !== null ? (int) $row['previous_assignee'] : null,
                'previous_assignee_user' => $this->mapPublicUser($row, 'previous_user_'),
                'new_assignee' => $row['new_assignee'] !== null ? (int) $row['new_assignee'] : null,
                'new_assignee_user' => $this->mapPublicUser($row, 'new_user_'),
                'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
                'created_at' => (string) $row['created_at'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Public view of a user joined under `$prefix`: never email, password hash or session data.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, username: string, first_name: string, last_name: string, role: string}|null
     */
    private function mapPublicUser(array $row, string $prefix): ?array
    {
        if (($row[$prefix . 'id'] ?? null) === null) {
            return null;
        }

        return [
            'id' => (int) $row[$prefix . 'id'],
            'username' => (string) $row[$prefix . 'username'],
            'first_name' => (string) $row[$prefix . 'first_name'],
            'last_name' => (string) $row[$prefix . 'last_name'],
            'role' => (string) $row[$prefix . 'role'],
        ];
    }

    public function userExists(int $userId): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM users
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToIncident(array $row): Incident
    {
        return new Incident(
            id: (int) $row['id'],
            incidentUuid: (string) $row['incident_uuid'],
            incidentNumber: (string) $row['incident_number'],
            title: (string) $row['title'],
            description: $row['description'] !== null
                ? (string) $row['description']
                : null,
            severity: (int) $row['severity'],
            status: (string) $row['status'],
            priority: (string) $row['priority'],
            assignedTo: $row['assigned_to'] !== null
                ? (int) $row['assigned_to']
                : null,
            detectedAt: (string) $row['detected_at'],
            acknowledgedAt: $row['acknowledged_at'] !== null
                ? (string) $row['acknowledged_at']
                : null,
            containedAt: $row['contained_at'] !== null
                ? (string) $row['contained_at']
                : null,
            resolvedAt: $row['resolved_at'] !== null
                ? (string) $row['resolved_at']
                : null,
            closedAt: $row['closed_at'] !== null
                ? (string) $row['closed_at']
                : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
