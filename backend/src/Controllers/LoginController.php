<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Services\AuthenticationService;

final class LoginController
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
        private readonly SessionManager $sessionManager,
    ) {
    }

    public function login(HttpRequest $request): void
    {
        $identifier = $request->input('identifier');
        $password = $request->input('password');

        if (
            !is_string($identifier)
            || !is_string($password)
            || trim($identifier) === ''
            || $password === ''
        ) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Invalid credentials.',
                ],
                401
            );

            return;
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
                401
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
                401
            );

            return;
        }

        $this->sessionManager->authenticate(
            userId: $user->id(),
            userUuid: $user->uuid(),
            role: $user->role(),
        );

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
        ]);
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
        ]);
    }
}
