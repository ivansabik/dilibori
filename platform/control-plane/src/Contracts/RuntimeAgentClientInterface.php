<?php

namespace Dilibori\ControlPlane\Contracts;

interface RuntimeAgentClientInterface
{
    /** @param array<string, mixed> $payload */
    public function enqueue(string $action, string $deploymentId, array $payload = []): array;

    /** @return array<string, mixed> */
    public function observedState(string $deploymentId): array;

    /** @return array<string, mixed> */
    public function health(string $deploymentId): array;
}
