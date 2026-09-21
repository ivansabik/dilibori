<?php

namespace Dilibori\ControlPlane\Http;

use Closure;

final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, handler: Closure}> */
    private array $routes = [];

    public function get(string $pattern, Closure $handler): void
    {
        $this->add(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, Closure $handler): void
    {
        $this->add(['POST'], $pattern, $handler);
    }

    public function patch(string $pattern, Closure $handler): void
    {
        $this->add(['PATCH'], $pattern, $handler);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $pattern, Closure $handler): void
    {
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if (!in_array($request->method, $route['methods'], true)) {
                continue;
            }

            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            $matched = new Request(
                $request->method,
                $request->path,
                $params,
                $request->json,
            );

            $response = ($route['handler'])($matched);

            return $response instanceof Response ? $response : Response::json(['result' => $response]);
        }

        return Response::error('Not found', 404);
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
