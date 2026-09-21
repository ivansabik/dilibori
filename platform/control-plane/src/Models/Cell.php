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

        return $row;
    }
}
