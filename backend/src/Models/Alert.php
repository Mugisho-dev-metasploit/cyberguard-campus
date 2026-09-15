<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Models;

final class Alert
{
    public function __construct(
        private readonly int $id,
        private readonly string $alertUuid,
        private readonly ?int $eventId,
        private readonly ?int $deviceId,
        private readonly string $source,
        private readonly string $alertType,
        private readonly int $severity,
        private readonly string $title,
        private readonly ?string $description,
        private readonly ?string $signature,
        private readonly ?string $category,
        private readonly string $status,
        private readonly string $detectedAt,
        private readonly ?string $acknowledgedAt,
        private readonly ?string $resolvedAt,
        private readonly ?int $assignedTo,
        private readonly string $createdAt,
        private readonly string $updatedAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function alertUuid(): string
    {
        return $this->alertUuid;
    }

    public function eventId(): ?int
    {
        return $this->eventId;
    }

    public function deviceId(): ?int
    {
        return $this->deviceId;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function alertType(): string
    {
        return $this->alertType;
    }

    public function severity(): int
    {
        return $this->severity;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function detectedAt(): string
    {
        return $this->detectedAt;
    }

    public function acknowledgedAt(): ?string
    {
        return $this->acknowledgedAt;
    }

    public function resolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function assignedTo(): ?int
    {
        return $this->assignedTo;
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
            'alert_uuid' => $this->alertUuid,
            'event_id' => $this->eventId,
            'device_id' => $this->deviceId,
            'source' => $this->source,
            'alert_type' => $this->alertType,
            'severity' => $this->severity,
            'title' => $this->title,
            'description' => $this->description,
            'signature' => $this->signature,
            'category' => $this->category,
            'status' => $this->status,
            'detected_at' => $this->detectedAt,
            'acknowledged_at' => $this->acknowledgedAt,
            'resolved_at' => $this->resolvedAt,
            'assigned_to' => $this->assignedTo,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
