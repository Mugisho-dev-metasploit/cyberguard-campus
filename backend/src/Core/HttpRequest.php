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
        );
    }
}
