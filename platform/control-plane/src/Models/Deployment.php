<?php

namespace Dilibori\ControlPlane\Models;

final class Deployment
{
    public const STATUSES = [
        'reserved',
        'creating_db',
        'creating_secrets',
        'installing',
        'creating_runtime',
        'configuring_route',
        'health_check',
        'active',
        'failed',
        'suspended',
        'migrating',
    ];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): array
    {
        $row['runtime_refs'] = is_string($row['runtime_refs'] ?? null)
            ? json_decode($row['runtime_refs'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['runtime_refs'] ?? []);

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function toPublic(array $row): array
    {
        return [
            'id' => $row['id'],
            'tenant_id' => $row['tenant_id'],
            'environment' => $row['environment'],
            'cell_id' => $row['cell_id'],
            'hostname' => $row['hostname'],
            'database_name' => $row['database_name'],
            'desired_release' => $row['desired_release'],
            'seed_bundle' => $row['seed_bundle'],
            'config_version' => (int) $row['config_version'],
            'config_checksum' => $row['config_checksum'],
            'status' => $row['status'],
            'suspension_state' => $row['suspension_state'],
            'backup_state' => $row['backup_state'],
            'runtime_refs' => $row['runtime_refs'] ?? [],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
