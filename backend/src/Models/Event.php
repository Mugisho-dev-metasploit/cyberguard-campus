<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Models;

final class Event
{
    public function __construct(
        private readonly int $id,
        private readonly string $eventUuid,
        private readonly ?int $deviceId,
        private readonly string $source,
        private readonly string $eventType,
        private readonly int $severity,
        private readonly string $eventTimestamp,
        private readonly ?string $srcIp,
        private readonly ?int $srcPort,
        private readonly ?string $dstIp,
        private readonly ?int $dstPort,
        private readonly ?string $protocol,
        private readonly ?string $signature,
        private readonly ?string $category,
        private readonly ?string $processedAt,
        private readonly string $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function eventUuid(): string
    {
        return $this->eventUuid;
    }

    public function deviceId(): ?int
    {
        return $this->deviceId;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function severity(): int
    {
        return $this->severity;
    }

    public function eventTimestamp(): string
    {
        return $this->eventTimestamp;
    }

    public function srcIp(): ?string
    {
        return $this->srcIp;
    }

    public function srcPort(): ?int
    {
        return $this->srcPort;
    }

    public function dstIp(): ?string
    {
        return $this->dstIp;
    }

    public function dstPort(): ?int
    {
        return $this->dstPort;
    }

    public function protocol(): ?string
    {
        return $this->protocol;
    }

    public function signature(): ?string
    {
        return $this->signature;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function processedAt(): ?string
    {
        return $this->processedAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_uuid' => $this->eventUuid,
            'device_id' => $this->deviceId,
            'source' => $this->source,
            'event_type' => $this->eventType,
            'severity' => $this->severity,
            'event_timestamp' => $this->eventTimestamp,
            'src_ip' => $this->srcIp,
            'src_port' => $this->srcPort,
            'dst_ip' => $this->dstIp,
            'dst_port' => $this->dstPort,
            'protocol' => $this->protocol,
            'signature' => $this->signature,
            'category' => $this->category,
            'processed_at' => $this->processedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
