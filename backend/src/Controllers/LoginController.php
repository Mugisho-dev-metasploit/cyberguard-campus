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
}
