<?php

namespace Dilibori\ControlPlane;

use Dilibori\ControlPlane\Contracts\RuntimeAgentClientInterface;
use Dilibori\ControlPlane\Services\AuditLogger;
use Dilibori\ControlPlane\Services\ConfigResolver;
use Dilibori\ControlPlane\Services\MigrationStateMachine;
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
        return $this->runtimeAgentForCell(null);
    }

    public function runtimeAgentForCell(?string $cellId): RuntimeAgentClient
    {
        $url = (string) $this->config['runtime_agent_url'];
        if ($cellId !== null && $cellId !== '') {
            $statement = $this->db()->prepare('SELECT agent_url FROM cells WHERE id = :id');
            $statement->execute(['id' => $cellId]);
            $row = $statement->fetch();
            if (is_array($row) && !empty($row['agent_url'])) {
                $url = rtrim((string) $row['agent_url'], '/');
            }
        }

        return new RuntimeAgentClient(
            $url,
            (string) $this->config['runtime_agent_token'],
        );
    }

    /** @return callable(?string): RuntimeAgentClientInterface */
    public function runtimeAgentResolver(): callable
    {
        return fn (?string $cellId) => $this->runtimeAgentForCell($cellId);
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
            $this->runtimeAgentResolver(),
            $this->secretStore(),
            $this->configResolver(),
            $this->audit(),
        );
    }

    public function migration(): MigrationStateMachine
    {
        return MigrationStateMachine::make(
            $this->runtimeAgentResolver(),
            $this->secretStore(),
            $this->configResolver(),
            $this->audit(),
        );
    }

    public function reconciler(): Reconciler
    {
        return Reconciler::make(
            $this->runtimeAgentResolver(),
            $this->secretStore(),
            $this->audit(),
        );
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }
}
