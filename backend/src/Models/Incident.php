<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Models;

final class Incident
{
    public function __construct(
        private readonly int $id,
        private readonly string $incidentUuid,
        private readonly string $incidentNumber,
        private readonly string $title,
        private readonly ?string $description,
        private readonly int $severity,
        private readonly string $status,
        private readonly string $priority,
        private readonly ?int $assignedTo,
        private readonly string $detectedAt,
        private readonly ?string $acknowledgedAt,
        private readonly ?string $containedAt,
        private readonly ?string $resolvedAt,
        private readonly ?string $closedAt,
        private readonly string $createdAt,
        private readonly string $updatedAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function incidentUuid(): string
    {
        return $this->incidentUuid;
    }

    public function incidentNumber(): string
    {
        return $this->incidentNumber;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function severity(): int
    {
        return $this->severity;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function priority(): string
    {
        return $this->priority;
    }

    public function assignedTo(): ?int
    {
        return $this->assignedTo;
    }

    public function detectedAt(): string
    {
        return $this->detectedAt;
    }

    public function acknowledgedAt(): ?string
    {
        return $this->acknowledgedAt;
    }

    public function containedAt(): ?string
    {
        return $this->containedAt;
    }

    public function resolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function closedAt(): ?string
    {
        return $this->closedAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_uuid' => $this->incidentUuid,
            'incident_number' => $this->incidentNumber,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_to' => $this->assignedTo,
            'detected_at' => $this->detectedAt,
            'acknowledged_at' => $this->acknowledgedAt,
            'contained_at' => $this->containedAt,
            'resolved_at' => $this->resolvedAt,
            'closed_at' => $this->closedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
