<?php

namespace Dilibori\ControlPlane\Http;

final class Request
{
    /** @param array<string, string> $routeParams */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $routeParams = [],
        public readonly ?array $json = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $json = null;

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $json = $decoded;
                }
            }
        }

        return new self($method, $uri, [], $json);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (is_array($this->json) && array_key_exists($key, $this->json)) {
            return $this->json[$key];
        }

        return $_POST[$key] ?? $default;
    }
}
