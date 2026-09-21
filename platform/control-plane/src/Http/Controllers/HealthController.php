<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Response;

final class HealthController
{
    public function __construct(private Application $app)
    {
    }

    public function show(): Response
    {
        try {
            $this->app->db()->query('SELECT 1');

            return Response::json([
                'status' => 'ok',
                'service' => $this->app->config()['name'],
                'database' => 'connected',
            ]);
        } catch (\Throwable $exception) {
            return Response::json([
                'status' => 'degraded',
                'service' => $this->app->config()['name'],
                'database' => 'unavailable',
                'error' => $exception->getMessage(),
            ], 503);
        }
    }
}
