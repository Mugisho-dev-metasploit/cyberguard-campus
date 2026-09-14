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
