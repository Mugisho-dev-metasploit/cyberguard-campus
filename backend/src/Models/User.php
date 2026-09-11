<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Models;

final class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $uuid,
        private readonly string $username,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $role,
        private readonly string $status,
        private readonly ?string $lastLoginAt,
        private readonly string $createdAt,
        private readonly string $updatedAt,
        private readonly ?string $deletedAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    public function deletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->deletedAt === null;
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }
}
