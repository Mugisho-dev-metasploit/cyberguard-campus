<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Core;

final class HttpRequest
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $routeParams
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $body,
        private readonly array $routeParams = [],
        private readonly bool $jsonValid = true,
        private readonly string $clientAddress = '',
        // APP074-05 — transport metadata. The defaults describe a same-origin JSON request, the
        // only kind in-process callers build; fromGlobals() always passes the real values.
        private readonly string $contentType = 'application/json',
        private readonly ?string $origin = null,
        private readonly string $serverOrigin = '',
        private readonly string $userAgent = '',   // APP074-11 — recorded (bounded) in audit_logs
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $requestUri = parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        );

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptPath = parse_url($scriptName, PHP_URL_PATH);

        $path = '/';

        if (is_string($requestUri) && $requestUri !== '') {
            $path = $requestUri;
        }

        if (is_string($scriptPath) && $scriptPath !== '' && str_starts_with($path, $scriptPath)) {
            $path = substr($path, strlen($scriptPath));
        }

        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            $body = $_POST;
            $jsonValid = true;
        } else {
            $decoded = json_decode(
                $rawBody,
                true
            );

            if (!is_array($decoded)) {
                $body = [];
                $jsonValid = false;
            } else {
                $body = $decoded;
                $jsonValid = true;
            }
        }

        return new self(
            method: $method,
            path: $path,
            body: $body,
            routeParams: [],
            jsonValid: $jsonValid,
            // The TCP peer only: no X-Forwarded-For or similar header is trusted (no proxy in front).
            clientAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            contentType: (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            origin: isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : null,
            serverOrigin: (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http')
                . '://' . (string) ($_SERVER['HTTP_HOST'] ?? ''),
            userAgent: (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function clientAddress(): string
    {
        return $this->clientAddress;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /** APP074-05 — true when the body is declared as JSON (media type only, parameters ignored). */
    public function isJson(): bool
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0])) === 'application/json';
    }

    /**
     * APP074-05 — false when a browser says the request comes from another origin. Browsers send
     * Origin on every POST; its absence (non-browser client) is not treated as cross-origin, while
     * the opaque origin "null" is.
     */
    public function isSameOrigin(): bool
    {
        return $this->origin === null || strcasecmp($this->origin, $this->serverOrigin) === 0;
    }

    public function jsonValid(): bool
    {
        return $this->jsonValid;
    }

    public function routeParam(string $key): ?string
    {
        return $this->routeParams[$key] ?? null;
    }

    /**
     * @param array<string, string> $routeParams
     */
    public function withRouteParams(array $routeParams): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            body: $this->body,
            routeParams: $routeParams,
            jsonValid: $this->jsonValid,
            clientAddress: $this->clientAddress,
            contentType: $this->contentType,
            origin: $this->origin,
            serverOrigin: $this->serverOrigin,
            userAgent: $this->userAgent,
        );
    }
}
