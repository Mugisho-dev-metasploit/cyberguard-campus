<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Exceptions\AuthenticationException;
use CyberGuard\Campus\Models\User;
use CyberGuard\Campus\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

final class AuthenticationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function authenticate(
        string $identifier,
        string $password,
    ): AuthenticationResult {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return AuthenticationResult::failure();
        }

        $user = $this->userRepository->findByUsernameOrEmail(
            $identifier
        );

        if ($user === null) {
            return AuthenticationResult::failure();
        }

        if (!$user->isActive()) {
            return AuthenticationResult::failure();
        }

        if (!password_verify($password, $user->passwordHash())) {
            return AuthenticationResult::failure();
        }

        $timestamp = (new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))->format('Y-m-d H:i:s.u');

        $this->userRepository->updateLastLoginAt(
            $user->id(),
            $timestamp
        );

        $updatedUser = $this->userRepository->findByUuid(
            $user->uuid()
        );

        if ($updatedUser === null) {
            throw new AuthenticationException(
                'Authenticated user could not be reloaded.'
            );
        }

        return AuthenticationResult::success($updatedUser);
    }

    /**
     * Re-reads the account of an authenticated session (APP-07.3.1). Null when the account
     * no longer exists or is no longer active: the session must then be revoked. The
     * returned user carries the current role, the only one to authorize with.
     */
    public function resolveSessionUser(int $userId): ?User
    {
        $user = $this->userRepository->findActiveById($userId);

        return $user !== null && $user->isActive() ? $user : null;
    }
}
