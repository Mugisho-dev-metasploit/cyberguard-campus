<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Repositories\AuditLogRepository;

/**
 * Authentication events in audit_logs (APP074-11).
 *
 * Recorded: account (when known), client address (validated), user agent (valid UTF-8, no
 * control characters, at most 255 characters), outcome. Never recorded: password, password
 * hash, the identifier typed at sign-in, session ID or cookie.
 * Every failed sign-in is recorded the same way, whether or not the account exists, so the
 * work done does not reveal it (APP-07.4.2). Throttled attempts are recorded at most once per
 * address and minute, so an attack cannot grow the table at request speed. Recording is best
 * effort: an audit failure never changes the authentication outcome.
 */
final class AuthenticationAudit
{
    public const LOGIN_SUCCESS = 'auth.login.success';
    public const LOGIN_FAILURE = 'auth.login.failure';
    public const LOGIN_THROTTLED = 'auth.login.throttled';
    public const LOGOUT = 'auth.logout';
    public const SESSION_REVOKED = 'auth.session.revoked';

    public const USER_AGENT_MAX_LENGTH = 255;
    public const THROTTLED_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly AuditLogRepository $repository,
    ) {
    }

    public function loginSucceeded(int $userId, HttpRequest $request): void
    {
        $this->record(self::LOGIN_SUCCESS, $userId, true, $request);
    }

    /** No account and no identifier: identical for unknown, inactive, locked, deleted and wrong password. */
    public function loginFailed(HttpRequest $request): void
    {
        $this->record(self::LOGIN_FAILURE, null, false, $request);
    }

    public function loginThrottled(HttpRequest $request): void
    {
        try {
            $address = $this->address($request);

            if ($address !== null && $this->repository->recordedRecently(self::LOGIN_THROTTLED, $address, self::THROTTLED_INTERVAL_SECONDS)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $this->record(self::LOGIN_THROTTLED, null, false, $request);
    }

    public function loggedOut(int $userId, HttpRequest $request): void
    {
        $this->record(self::LOGOUT, $userId, true, $request);
    }

    public function sessionRevoked(int $userId, HttpRequest $request): void
    {
        $this->record(self::SESSION_REVOKED, $userId, false, $request);
    }

    private function record(string $action, ?int $userId, bool $success, HttpRequest $request): void
    {
        try {
            $this->repository->record($action, $userId, $success, $this->address($request), $this->userAgent($request));
        } catch (\Throwable) {
            // Best effort: the sign-in, sign-out or revocation outcome never depends on the audit.
        }
    }

    private function address(HttpRequest $request): ?string
    {
        $address = filter_var($request->clientAddress(), FILTER_VALIDATE_IP);

        return $address === false ? null : $address;
    }

    private function userAgent(HttpRequest $request): ?string
    {
        $agent = mb_scrub($request->userAgent(), 'UTF-8');
        $agent = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $agent);
        $agent = trim(mb_substr($agent, 0, self::USER_AGENT_MAX_LENGTH, 'UTF-8'));

        return $agent === '' ? null : $agent;
    }
}
