<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Middleware;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Services\AuthenticationAudit;
use CyberGuard\Campus\Services\AuthenticationService;

final class AuthenticationMiddleware
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly AuthenticationService $authenticationService,
        private readonly ?AuthenticationAudit $audit = null,
    ) {
    }

    public function handle(
        HttpRequest $request,
        callable $next,
    ): void {
        if (!$this->sessionManager->isAuthenticated()) {
            $this->unauthenticated();

            return;
        }

        // The account is re-read on every protected request (APP-07.3.1): a deleted or
        // no longer active account revokes the session at once, and authorization uses the
        // role stored in the database, not the one recorded at sign-in.
        $userId = $this->sessionManager->userId();
        $user = $userId === null
            ? null
            : $this->authenticationService->resolveSessionUser($userId);

        if ($user === null) {
            $this->sessionManager->destroy();

            if ($userId !== null) {
                $this->audit?->sessionRevoked($userId, $request);
            }

            $this->unauthenticated();

            return;
        }

        $this->sessionManager->refreshRole($user->role());

        $next($request);
    }

    /** Same response whatever the reason: never says whether or why an account was disabled. */
    private function unauthenticated(): void
    {
        HttpResponse::json(
            [
                'success' => false,
                'message' => 'Authentication required.',
            ],
            401
        );
    }
}
