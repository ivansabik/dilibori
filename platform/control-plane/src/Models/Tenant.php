<?php

namespace Dilibori\ControlPlane\Models;

final class Tenant
{
    /** @param array<string, mixed> $row */
    public static function toPublic(array $row): array
    {
        return [
            'id' => $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'noindex' => self::asBool($row['noindex'] ?? false),
            'created_at' => $row['created_at'],
        ];
    }

    public static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
