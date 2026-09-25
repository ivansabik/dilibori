<?php

declare(strict_types=1);

namespace Dilibori\Platform\Health;

trait ProbesViaLoopback
{
    protected function baseUrl(): ?string
    {
        if ($this->shouldProbeLoopback()) {
            return 'http://127.0.0.1';
        }

        return parent::baseUrl();
    }

    protected function httpClient()
    {
        $client = parent::httpClient();
        $host = $this->expectedHost();
        if ($this->shouldProbeLoopback() && $host !== '') {
            return $client->withHeaders(['Host' => $host]);
        }

        return $client;
    }

    protected function shouldProbeLoopback(): bool
    {
        return $this->expectedHost() !== '';
    }

    protected function expectedHost(): string
    {
        return strtolower(trim((string) (getenv('EXPECTED_HOST') ?: '')));
    }
}
