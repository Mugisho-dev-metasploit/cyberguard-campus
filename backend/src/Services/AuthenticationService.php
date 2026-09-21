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
    /**
     * APP-07.4.2 — the bcrypt hash checked when no active account matches the identifier
     * (unknown, deleted, inactive or locked), so every failed sign-in costs one bcrypt
     * verification, exactly like a wrong password on an active account. Generated once from 32
     * random bytes that were discarded: no password matches it, and the result is ignored anyway.
     * Same algorithm and cost as the stored hashes (bcrypt, cost 12). Not a secret; not stored.
     */
    private const REFERENCE_HASH = '$2y$12$6fSr5qShUeQhRlXaL1k1..gJCs4nuZ.qzYDMhDOXfs541mHoKLXM.';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * APP-07.4.3 — $password is replaced by Object(SensitiveParameterValue) in any exception
     * trace (logged by PHP for an uncaught exception), whatever the trace settings.
     */
    public function authenticate(
        string $identifier,
        #[\SensitiveParameter] string $password,
    ): AuthenticationResult {
        $identifier = trim($identifier);

        if ($identifier === '' || $password === '') {
            return AuthenticationResult::failure();
        }

        $user = $this->userRepository->findByUsernameOrEmail(
            $identifier
        );

        // Only an active account may sign in; any other outcome gets the reference hash, so the
        // work done does not reveal whether, or in which state, the account exists (APP-07.4.2).
        if ($user !== null && !$user->isActive()) {
            $user = null;
        }

        // Exactly one bcrypt verification per attempt, whatever the account state.
        $passwordMatches = password_verify(
            $password,
            $user?->passwordHash() ?? self::REFERENCE_HASH
        );

        if ($user === null || !$passwordMatches) {
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
