<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateIncident(int $incidentId, array $payload): array
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

            if (!is_string($status) || !in_array($status, ['open', 'acknowledged', 'investigating', 'contained', 'resolved', 'closed'], true)) {
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

        $incident = $this->incidentRepository->findById($incidentId);

        if ($incident === null) {
            throw new RuntimeException(
                'Incident not found.',
                404,
            );
        }

        $updatedIncident = $this->incidentRepository->update($incidentId, $normalized);

        return $updatedIncident->toArray();
    }
}
