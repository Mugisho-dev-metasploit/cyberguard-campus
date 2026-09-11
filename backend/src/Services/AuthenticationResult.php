<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Models\User;

final class AuthenticationResult
{
    private function __construct(
        private readonly bool $authenticated,
        private readonly ?User $user,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(
            authenticated: true,
            user: $user,
        );
    }

    public static function failure(): self
    {
        return new self(
            authenticated: false,
            user: null,
        );
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function user(): ?User
    {
        return $this->user;
    }
}
