<?php

namespace Dilibori\ControlPlane\Models;

final class Cell
{
    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): array
    {
        $row['capacity_metadata'] = is_string($row['capacity_metadata'] ?? null)
            ? json_decode($row['capacity_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['capacity_metadata'] ?? []);

        if (array_key_exists('schedulable', $row)) {
            $row['schedulable'] = self::asBool($row['schedulable']);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function toPublic(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'provider' => $row['provider'],
            'capacity_metadata' => $row['capacity_metadata'] ?? [],
            'agent_url' => $row['agent_url'] ?? null,
            'schedulable' => self::asBool($row['schedulable'] ?? true),
            'created_at' => $row['created_at'],
        ];
    }

    public static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 't'], true);
        }

        return (bool) $value;
    }
}
