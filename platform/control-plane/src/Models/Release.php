<?php

namespace Dilibori\ControlPlane\Models;

final class Release
{
    /** @param array<string, mixed> $row */
    public static function toPublic(array $row): array
    {
        return [
            'id' => $row['id'],
            'image_digest' => $row['image_digest'],
            'image_tag' => $row['image_tag'],
            'notes' => $row['notes'],
            'created_at' => $row['created_at'],
        ];
    }
}
