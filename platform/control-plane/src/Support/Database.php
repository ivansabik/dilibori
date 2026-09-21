<?php

namespace Dilibori\ControlPlane\Support;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = controlPlaneDatabaseConfig();
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        // Existing local volumes skip initdb; keep schema drift one statement.
        self::$connection->exec(
            'ALTER TABLE tenants ADD COLUMN IF NOT EXISTS noindex BOOLEAN NOT NULL DEFAULT FALSE',
        );

        return self::$connection;
    }
}
