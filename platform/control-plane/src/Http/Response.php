<?php

namespace Dilibori\ControlPlane\Http;

final class Response
{
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
    ) {
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function error(string $message, int $status = 400): self
    {
        return self::json(['error' => $message], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
