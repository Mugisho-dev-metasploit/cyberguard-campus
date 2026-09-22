<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

final class LoginController
{
    /**
     * APP-07.4.6 — every sign-in and sign-out answer (success, failure, throttling, error) carries
     * user or session state: no browser or proxy may store it. Set explicitly here rather than
     * relying on PHP's session cache limiter, which only applies when a session is started.
     */
    private const NO_STORE = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
    ];

    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly SessionManager $sessionManager,
        private readonly ?LoginThrottle $loginThrottle = null,
    ) {
    }

    public function login(HttpRequest $request): void
    {
        // APP074-05 — login CSRF: a cross-site HTML form can only send text/plain, multipart or
        // urlencoded bodies; requiring JSON forces a CORS preflight, which this API never grants.
        // A browser-declared foreign Origin is refused as well. Neither counts as a sign-in attempt.
        if (!$request->isJson()) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unsupported content type.',
                ],
                415,
                self::NO_STORE
            );

            return;
        }

        if (!$request->isSameOrigin()) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Cross-origin sign-in refused.',
                ],
                403,
                self::NO_STORE
            );

            return;
        }

        $identifier = $request->input('identifier');
        $password = $request->input('password');

        if (
            !is_string($identifier)
            || !is_string($password)
            || trim($identifier) === ''
            || $password === ''
            // Oversized values (APP-07.4.4) are refused here, before the throttle and any query.
            || !AuthenticationService::withinLimits($identifier, $password)
        ) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Invalid credentials.',
                ],
                401,
                self::NO_STORE
            );

            return;
        }

        // Throttling (APP-07.4.1) runs before any user lookup or password check, and answers the
        // same way whether the account exists. Keyed on the identifier as sent, not on the account.
        $source = $request->clientAddress();

        if ($this->loginThrottle !== null) {
            $wait = $this->loginThrottle->attempt($identifier, $source);

            if ($wait !== null) {
                HttpResponse::json(
                    [
                        'success' => false,
                        'message' => 'Too many sign-in attempts. Try again later.',
                    ],
                    429,
                    ['Retry-After' => (string) $wait] + self::NO_STORE
                );

                return;
            }
        }

        $result = $this->authenticationService->authenticate(
            identifier: $identifier,
            password: $password,
        );

        if (!$result->isAuthenticated()) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Invalid credentials.',
                ],
                401,
                self::NO_STORE
            );

            return;
        }

        $user = $result->user();

        if ($user === null) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Authentication failed.',
                ],
                401,
                self::NO_STORE
            );

            return;
        }

        // APP-07.4.5 — a sign-in is reported only once its session is established; otherwise a
        // generic 500 (same convention as the other controllers), and the throttle is not cleared.
        try {
            $established = $this->sessionManager->authenticate(
                userId: $user->id(),
                userUuid: $user->uuid(),
                role: $user->role(),
            );
        } catch (\Throwable) {
            $established = false;
        }

        if (!$established) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to sign in.',
                ],
                500,
                self::NO_STORE
            );

            return;
        }

        // APP074-19 — the session is established: failing to reset the counters (database error)
        // must not turn the sign-in into an error; the buckets simply expire on their own.
        try {
            $this->loginThrottle?->clear($identifier, $source);
        } catch (\Throwable) {
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Authentication successful.',
            'user' => [
                'uuid' => $user->uuid(),
                'username' => $user->username(),
                'email' => $user->email(),
                'first_name' => $user->firstName(),
                'last_name' => $user->lastName(),
                'role' => $user->role(),
            ],
        ], 200, self::NO_STORE);
    }

    /**
     * POST /logout (APP-07.3.2). The session to end is the one named by the session cookie,
     * never anything sent in the body or the URL: the request body is not read. Always the
     * same answer, so it never tells whether a session existed or who was signed in.
     */
    public function logout(HttpRequest $request): void
    {
        $this->sessionManager->logout();

        HttpResponse::json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ], 200, self::NO_STORE);
    }
}
