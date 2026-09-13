<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Middleware;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;

final class AuthenticationMiddleware
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {
    }

    public function handle(
        HttpRequest $request,
        callable $next,
    ): void {
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

        $next($request);
    }
}
