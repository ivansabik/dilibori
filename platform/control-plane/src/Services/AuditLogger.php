<?php

namespace Dilibori\ControlPlane\Services;

use Dilibori\ControlPlane\Support\Database;
use Dilibori\ControlPlane\Support\Uuid;
use PDO;

final class AuditLogger
{
    public function __construct(
        private PDO $db,
        private string $defaultActor = 'control-plane',
    ) {
    }

    public static function make(string $defaultActor): self
    {
        return new self(Database::connection(), $defaultActor);
    }

    /** @param array<string, mixed> $payload */
    public function log(
        string $action,
        ?string $tenantId = null,
        ?string $deploymentId = null,
        ?string $reason = null,
        array $payload = [],
        ?string $actor = null,
    ): string {
        $id = Uuid::v4();
        $statement = $this->db->prepare(
            'INSERT INTO audit_events (id, actor, action, tenant_id, deployment_id, reason, payload)
             VALUES (:id, :actor, :action, :tenant_id, :deployment_id, :reason, :payload::jsonb)',
        );

        $statement->execute([
            'id' => $id,
            'actor' => $actor ?? $this->defaultActor,
            'action' => $action,
            'tenant_id' => $tenantId,
            'deployment_id' => $deploymentId,
            'reason' => $reason,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
