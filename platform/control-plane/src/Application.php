<?php

namespace Dilibori\ControlPlane;

use Dilibori\ControlPlane\Services\AuditLogger;
use Dilibori\ControlPlane\Services\ConfigResolver;
use Dilibori\ControlPlane\Services\ProvisioningStateMachine;
use Dilibori\ControlPlane\Services\Reconciler;
use Dilibori\ControlPlane\Services\RuntimeAgentClient;
use Dilibori\ControlPlane\Services\SecretStore;
use Dilibori\ControlPlane\Support\Database;
use PDO;

final class Application
{
    /** @var array<string, mixed> */
    private array $config;

    private ?PDO $db = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__) . '/config/app.php';
    }

    public function db(): PDO
    {
        return $this->db ??= Database::connection();
    }

    public function runtimeAgent(): RuntimeAgentClient
    {
        return new RuntimeAgentClient(
            (string) $this->config['runtime_agent_url'],
            (string) $this->config['runtime_agent_token'],
        );
    }

    public function audit(): AuditLogger
    {
        return AuditLogger::make((string) $this->config['default_actor']);
    }

    public function secretStore(): SecretStore
    {
        return SecretStore::make(
            (string) $this->config['secrets_path'],
            (string) $this->config['secret_key'],
        );
    }

    public function configResolver(): ConfigResolver
    {
        return new ConfigResolver((string) $this->config['profiles_path']);
    }

    public function provisioning(): ProvisioningStateMachine
    {
        return ProvisioningStateMachine::make(
            $this->runtimeAgent(),
            $this->secretStore(),
            $this->configResolver(),
            $this->audit(),
        );
    }

    public function reconciler(): Reconciler
    {
        return Reconciler::make($this->runtimeAgent(), $this->secretStore(), $this->audit());
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }
}
