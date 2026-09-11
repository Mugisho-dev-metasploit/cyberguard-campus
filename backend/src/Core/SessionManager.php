<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Core;

use RuntimeException;

final class SessionManager
{
    private const SESSION_STARTED_KEY = '__session_started';

    private const SESSION_USER_ID = 'user_id';
    private const SESSION_USER_UUID = 'user_uuid';
    private const SESSION_ROLE = 'role';
    private const SESSION_AUTHENTICATED = 'authenticated';

    private const SESSION_LIFETIME = 1800;

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Cannot start session because headers have already been sent.'
            );
        }

        $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';

        session_name(
            $_ENV['SESSION_NAME'] ?? 'cyberguard_session'
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isProduction,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set(
            'session.use_strict_mode',
            '1'
        );

        ini_set(
            'session.use_only_cookies',
            '1'
        );

        ini_set(
            'session.cookie_httponly',
            '1'
        );

        ini_set(
            'session.cookie_samesite',
            'Lax'
        );

        if ($isProduction) {
            ini_set(
                'session.cookie_secure',
                '1'
            );
        }

        session_start();

        $_SESSION[self::SESSION_STARTED_KEY] = time();
    }

    public function authenticate(
        int $userId,
        string $userUuid,
        string $role,
    ): void {
        $this->start();

        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_ID] = $userId;
        $_SESSION[self::SESSION_USER_UUID] = $userUuid;
        $_SESSION[self::SESSION_ROLE] = $role;
        $_SESSION[self::SESSION_AUTHENTICATED] = true;
        $_SESSION[self::SESSION_STARTED_KEY] = time();
    }

    public function isAuthenticated(): bool
    {
        $this->start();

        if (
            !isset($_SESSION[self::SESSION_AUTHENTICATED])
            || $_SESSION[self::SESSION_AUTHENTICATED] !== true
        ) {
            return false;
        }

        $startedAt = $_SESSION[self::SESSION_STARTED_KEY] ?? null;

        if (
            !is_int($startedAt)
            || (time() - $startedAt) > self::SESSION_LIFETIME
        ) {
            $this->destroy();

            return false;
        }

        return true;
    }

    public function userId(): ?int
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $userId = $_SESSION[self::SESSION_USER_ID] ?? null;

        return is_int($userId) ? $userId : null;
    }

    public function userUuid(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $uuid = $_SESSION[self::SESSION_USER_UUID] ?? null;

        return is_string($uuid) ? $uuid : null;
    }

    public function role(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        $role = $_SESSION[self::SESSION_ROLE] ?? null;

        return is_string($role) ? $role : null;
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }
}
