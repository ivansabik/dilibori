<?php

namespace Dilibori\ControlPlane\Services;

use RuntimeException;

final class ConfigResolver
{
    public function __construct(
        private string $profilesPath,
    ) {
    }

    /**
     * @param array<string, mixed> $cellDefaults
     * @param array<string, mixed> $tenantOverrides
     * @return array{effective: array<string, mixed>, checksum: string, version: int}
     */
    public function resolve(
        string $environment,
        array $cellDefaults = [],
        array $tenantOverrides = [],
        int $version = 1,
    ): array {
        $profileDoc = $this->loadProfile($environment);
        $profileConfig = $profileDoc['config'] ?? $profileDoc;
        $allowlist = $profileDoc['allowlisted_tenant_overrides']
            ?? ['MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'ADMIN_EMAIL', 'APP_URL'];

        $filteredOverrides = [];
        foreach ($tenantOverrides as $key => $value) {
            if (in_array($key, $allowlist, true)) {
                $filteredOverrides[$key] = $value;
            } elseif (in_array($key, $allowlist, true) === false && array_key_exists($key, $profileConfig)) {
                // Reject attempts to override non-allowlisted keys that exist on the profile
                if (in_array($key, ['APP_DEBUG', 'APP_ENV', 'ALLOW_SOURCE_MOUNTS', 'SESSION_SECURE_COOKIE'], true)) {
                    throw new RuntimeException("Tenant override not allowlisted: {$key}");
                }
            }
        }

        $effective = array_merge($profileConfig, $cellDefaults, $filteredOverrides);
        $this->validateInvariants($environment, $profileDoc, $effective);

        ksort($effective);
        $checksum = hash('sha256', json_encode($effective, JSON_THROW_ON_ERROR));

        return [
            'effective' => $effective,
            'checksum' => $checksum,
            'version' => $version,
        ];
    }

    /** @return array<string, mixed> */
    private function loadProfile(string $environment): array
    {
        $file = match ($environment) {
            'production' => 'production-like.json',
            default => 'development.json',
        };

        $path = rtrim($this->profilesPath, '/') . '/' . $file;
        if (!is_readable($path)) {
            // Fallback to bare environment name
            $alt = rtrim($this->profilesPath, '/') . '/' . $environment . '.json';
            if (!is_readable($alt)) {
                return ['config' => []];
            }
            $path = $alt;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid profile JSON: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $profileDoc
     * @param array<string, mixed> $effective
     */
    private function validateInvariants(string $environment, array $profileDoc, array $effective): void
    {
        $invariants = $profileDoc['invariants'] ?? [];

        if (($invariants['APP_DEBUG'] ?? null) === false) {
            $debug = $effective['APP_DEBUG'] ?? false;
            if ($debug === true || in_array(strtolower((string) $debug), ['1', 'true', 'yes', 'on'], true)) {
                throw new RuntimeException('Production invariant violated: APP_DEBUG must be false');
            }
        }

        if (($invariants['forbid_source_mounts'] ?? false) && !empty($effective['ALLOW_SOURCE_MOUNTS'])) {
            throw new RuntimeException('Production invariant violated: source mounts forbidden');
        }

        if (($invariants['require_secure_cookies'] ?? false) && empty($effective['SESSION_SECURE_COOKIE'])) {
            throw new RuntimeException('Production invariant violated: secure cookies required');
        }

        if ($environment === 'production') {
            $effective['APP_DEBUG'] = false;
            $effective['APP_ENV'] = 'production';
        }
    }
}
