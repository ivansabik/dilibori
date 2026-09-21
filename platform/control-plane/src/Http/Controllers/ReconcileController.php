<?php

namespace Dilibori\ControlPlane\Http\Controllers;

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;

final class ReconcileController
{
    public function __construct(private Application $app)
    {
    }

    public function run(Request $request): Response
    {
        $actor = $_SERVER['HTTP_X_ACTOR'] ?? $this->app->config()['default_actor'];
        $result = $this->app->reconciler()->reconcile(is_string($actor) ? $actor : null);

        return Response::json($result);
    }
}
