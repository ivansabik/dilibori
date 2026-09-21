<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Contracts\RuntimeAgentClientInterface;

final class RuntimeAgentClient implements RuntimeAgentClientInterface
{
    public function __construct(
        private string $baseUrl,
        private string $token,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $action, string $deploymentId, array $payload = []): array
    {
        return $this->request('POST', "/deployments/{$deploymentId}/tasks", [
            'action' => $action,
            'payload' => $payload,
        ]);
    }

    public function observedState(string $deploymentId): array
    {
        return $this->request('GET', "/deployments/{$deploymentId}/state");
    }

    public function health(string $deploymentId): array
    {
        return $this->request('GET', "/deployments/{$deploymentId}/health");
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 900,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            return [
                'ok' => false,
                'error' => 'runtime agent unreachable',
                'url' => $url,
            ];
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'invalid runtime agent response',
                'status' => $status,
                'raw' => $result,
            ];
        }

        if ($status >= 400) {
            $detail = $decoded['detail'] ?? $decoded['error'] ?? $result;
            if (is_array($detail)) {
                $detail = json_encode($detail);
            }

            return [
                'ok' => false,
                'error' => (string) $detail,
                'status' => $status,
                'detail' => $decoded['detail'] ?? null,
            ];
        }

        if (array_key_exists('ok', $decoded) && $decoded['ok'] === false) {
            return $decoded;
        }

        $decoded['ok'] = $decoded['ok'] ?? true;

        return $decoded;
    }
}
