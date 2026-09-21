<?php

function controlPlaneDatabaseConfig(): array
{
    $url = getenv('DATABASE_URL');
    if ($url !== false && $url !== '') {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RuntimeException('Invalid DATABASE_URL');
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (string) ($parts['port'] ?? 5432),
            'database' => ltrim($parts['path'] ?? '/control_plane', '/'),
            'username' => urldecode($parts['user'] ?? 'postgres'),
            'password' => urldecode($parts['pass'] ?? ''),
        ];
    }

    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '5432',
        'database' => getenv('DB_DATABASE') ?: 'control_plane',
        'username' => getenv('DB_USERNAME') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: '',
    ];
}
