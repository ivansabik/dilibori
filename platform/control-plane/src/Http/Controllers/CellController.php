<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Response;

final class CellController
{
    public function __construct(private Application $app)
    {
    }

    public function index(): Response
    {
        $rows = $this->app->db()->query(
            'SELECT id, name, provider, capacity_metadata, created_at FROM cells ORDER BY name',
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['capacity_metadata'] = is_string($row['capacity_metadata'])
                ? json_decode($row['capacity_metadata'], true)
                : $row['capacity_metadata'];
        }

        return Response::json(['cells' => $rows]);
    }
}
