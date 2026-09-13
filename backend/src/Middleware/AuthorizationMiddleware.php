<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Middleware;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use InvalidArgumentException;

final class AuthorizationMiddleware
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {
    }

    /**
     * @param list<string> $allowedRoles
     */
    public function handle(
        HttpRequest $request,
        array $allowedRoles,
        callable $next,
    ): void {
        if ($allowedRoles === []) {
            throw new InvalidArgumentException(
                'At least one allowed role is required.'
            );
        }

        if (!$this->sessionManager->isAuthenticated()) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Authentication required.',
                ],
                401
            );

            return;
        }

        $role = $this->sessionManager->role();

        if (
            $role === null
            || !in_array($role, $allowedRoles, true)
        ) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Insufficient permissions.',
                ],
                403
            );

            return;
        }

        $next($request);
    }
}
