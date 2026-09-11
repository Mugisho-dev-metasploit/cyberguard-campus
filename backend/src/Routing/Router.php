<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Routing;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use RuntimeException;

final class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $routes = [];

    public function get(
        string $path,
        callable $handler,
    ): void {
        $this->add(
            method: 'GET',
            path: $path,
            handler: $handler,
        );
    }

    public function post(
        string $path,
        callable $handler,
    ): void {
        $this->add(
            method: 'POST',
            path: $path,
            handler: $handler,
        );
    }

    public function dispatch(HttpRequest $request): void
    {
        $method = $request->method();
        $path = $request->path();

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Route not found.',
                ],
                404
            );

            return;
        }

        $handler($request);
    }

    private function add(
        string $method,
        string $path,
        callable $handler,
    ): void {
        $method = strtoupper($method);

        if ($path === '') {
            throw new RuntimeException(
                'Route path cannot be empty.'
            );
        }

        $this->routes[$method][$path] = $handler;
    }
}
