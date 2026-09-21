<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Exceptions;

use RuntimeException;

/**
 * The authenticated user's role does not allow the requested action (HTTP 403).
 */
final class AuthorizationException extends RuntimeException
{
}
