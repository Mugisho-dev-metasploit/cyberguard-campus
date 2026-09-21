<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Core;

use Closure;
use RuntimeException;

/**
 * Server-side session for authenticated users.
 *
 * Absolute lifetime: a session is valid for SESSION_LIFETIME_SECONDS from the moment the
 * user signed in (T0), whatever the activity in between. T0 is written once, in
 * authenticate(), and never refreshed by later requests (no sliding expiration).
 * The decision is made on the server from $_SESSION only; nothing sent by the client
 * is read to decide it.
 */
final class SessionManager
{
    private const SESSION_STARTED_KEY = '__session_started';

    private const SESSION_USER_ID = 'user_id';
    private const SESSION_USER_UUID = 'user_uuid';
    private const SESSION_ROLE = 'role';
    private const SESSION_AUTHENTICATED = 'authenticated';

    /** 6 hours. Expired when now >= T0 + SESSION_LIFETIME_SECONDS. */
    public const SESSION_LIFETIME_SECONDS = 21600;

    /** Set once the session has been destroyed in this request: it is not restarted. */
    private bool $invalidated = false;

    /**
     * @param (Closure(): int)|null $clock Current Unix time; defaults to time(). Injected by tests only.
     */
    public function __construct(
        private readonly ?Closure $clock = null,
    ) {
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private function configureSessionSettings(): void
    {
        if (headers_sent()) {
            throw new RuntimeException(
                'Cannot start session because headers have already been sent.'
            );
        }

        $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';

        session_name(
            $this->sessionName()
        );

        // The browser cookie ends with the session. PHP only sends it when a session ID is
        // issued (sign-in), so its expiry is T0 + 6h and is not pushed back by activity.
        // The server check in isAuthenticated() remains the authority.
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME_SECONDS,
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

        // Keep server-side session data at least as long as a session may be valid,
        // so an idle but unexpired session is not garbage-collected early.
        ini_set(
            'session.gc_maxlifetime',
            (string) self::SESSION_LIFETIME_SECONDS
        );

        if ($isProduction) {
            ini_set(
                'session.cookie_secure',
                '1'
            );
        }
    }

    private function sessionName(): string
    {
        return $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
    }

    /**
     * Opens the session sent by the browser, if any. Never writes the session start time:
     * only authenticate() does, so later requests cannot extend the session.
     * Returns whether a session is active afterwards (session_start() can fail).
     */
    public function start(): bool
    {
        if ($this->invalidated) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $this->configureSessionSettings();

        return session_start() && session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Signs the user in on a new session. Returns false — leaving no session data, no stored
     * session and an expired cookie — when the session could not be established (APP-07.4.5):
     * the caller must then not report a sign-in. Rethrows any exception after the same cleanup.
     */
    public function authenticate(
        int $userId,
        string $userUuid,
        string $role,
    ): bool {
        try {
            // Session fixation protection: resume whatever session the browser presented, drop
            // its data, then move to a new ID and delete the old one from the store.
            // (A custom ID forced with session_id() would be rejected by strict mode.)
            $this->invalidated = false;

            if (!$this->start()) {
                $this->abandon();

                return false;
            }

            $previousId = session_id();
            session_unset();

            // The identity is written only once the session is active under a new ID.
            if (
                !session_regenerate_id(true)
                || session_status() !== PHP_SESSION_ACTIVE
                || session_id() === ''
                || session_id() === $previousId
            ) {
                $this->abandon();

                return false;
            }

            $_SESSION[self::SESSION_USER_ID] = $userId;
            $_SESSION[self::SESSION_USER_UUID] = $userUuid;
            $_SESSION[self::SESSION_ROLE] = $role;
            $_SESSION[self::SESSION_AUTHENTICATED] = true;
            // T0: written here only, once per sign-in.
            $_SESSION[self::SESSION_STARTED_KEY] = $this->now();

            return true;
        } catch (\Throwable $exception) {
            $this->abandon();

            throw $exception;
        }
    }

    /**
     * A sign-in whose session could not be established leaves nothing usable: no data, the
     * session deleted when possible — otherwise closed without writing — and the cookie expired.
     */
    private function abandon(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->destroy();
        } else {
            $this->expireCookie();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }

        $this->invalidated = true;
    }

    public function isAuthenticated(): bool
    {
        // No session cookie: nothing to check, and no session is created for the request.
        if (!isset($_COOKIE[$this->sessionName()])) {
            return false;
        }

        $this->start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if (
            !isset($_SESSION[self::SESSION_AUTHENTICATED])
            || $_SESSION[self::SESSION_AUTHENTICATED] !== true
        ) {
            // Unknown, stale or unauthenticated ID (strict mode gave it a new, empty session):
            // nothing worth keeping, so no session is left behind and the cookie is cleared.
            $this->destroy();

            return false;
        }

        $startedAt = $_SESSION[self::SESSION_STARTED_KEY] ?? null;

        if (
            !is_int($startedAt)
            || $this->now() >= $startedAt + self::SESSION_LIFETIME_SECONDS
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

    /**
     * Replaces the role recorded at sign-in with the account's current role, read from the
     * database for this request (APP-07.3.1). The session start time is not touched.
     */
    public function refreshRole(string $role): void
    {
        if ($this->isAuthenticated()) {
            $_SESSION[self::SESSION_ROLE] = $role;
        }
    }

    /**
     * Signs out (APP-07.3.2): ends the session presented by the browser cookie, whatever its
     * state (valid, expired, unknown or already destroyed). Without a session cookie there is
     * nothing to end and no session is created. The same outcome in every case, by design.
     */
    public function logout(): void
    {
        if (!isset($_COOKIE[$this->sessionName()])) {
            return;
        }

        $this->start();
        $this->destroy();
    }

    /**
     * Ends the session: clears its data, deletes it from the server store and expires the
     * browser cookie. The same ID cannot be used again (strict mode rejects unknown IDs).
     */
    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        $this->expireCookie();

        session_destroy();
        $this->invalidated = true;
    }

    /** Tells the browser to drop the session cookie (same name, path and attributes as issued). */
    private function expireCookie(): void
    {
        if (ini_get('session.use_cookies') && !headers_sent()) {
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
    }
}
