<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Dilibori\ControlPlane\Application;
use Dilibori\ControlPlane\Http\Controllers\AdminController;
use Dilibori\ControlPlane\Http\Controllers\CellController;
use Dilibori\ControlPlane\Http\Controllers\DeploymentController;
use Dilibori\ControlPlane\Http\Controllers\HealthController;
use Dilibori\ControlPlane\Http\Controllers\ReconcileController;
use Dilibori\ControlPlane\Http\Controllers\ReleaseController;
use Dilibori\ControlPlane\Http\Controllers\TenantController;
use Dilibori\ControlPlane\Http\Request;
use Dilibori\ControlPlane\Http\Response;
use Dilibori\ControlPlane\Http\Router;

$app = new Application();
$request = Request::fromGlobals();
$router = new Router();

$health = new HealthController($app);
$tenants = new TenantController($app);
$deployments = new DeploymentController($app);
$cells = new CellController($app);
$releases = new ReleaseController($app);
$reconcile = new ReconcileController($app);
$admin = new AdminController($app);

$router->get('/', fn () => $admin->dashboard());
$router->get('/health', fn () => $health->show());

$router->get('/tenants', fn () => $tenants->index());
$router->post('/tenants', fn (Request $request) => $tenants->store($request));
$router->get('/tenants/{id}', fn (Request $request) => $tenants->show($request));
$router->patch('/tenants/{id}', fn (Request $request) => $tenants->update($request));
$router->post('/tenants/{id}/deployments', fn (Request $request) => $deployments->storeForTenant($request));

$router->get('/deployments/{id}', fn (Request $request) => $deployments->show($request));
$router->get('/deployments/{id}/status', fn (Request $request) => $deployments->status($request));
$router->post('/deployments/{id}/provision', fn (Request $request) => $deployments->provision($request));
$router->post('/deployments/{id}/suspend', fn (Request $request) => $deployments->suspend($request));
$router->post('/deployments/{id}/resume', fn (Request $request) => $deployments->resume($request));
$router->post('/deployments/{id}/deploy', fn (Request $request) => $deployments->deploy($request));
$router->post('/deployments/{id}/rollback', fn (Request $request) => $deployments->rollback($request));
$router->post('/deployments/{id}/backup', fn (Request $request) => $deployments->backup($request));
$router->post('/deployments/{id}/restore', fn (Request $request) => $deployments->restore($request));
$router->post('/deployments/{id}/support-access', fn (Request $request) => $deployments->supportAccess($request));

$router->post('/reconcile', fn (Request $request) => $reconcile->run($request));

$router->get('/cells', fn () => $cells->index());
$router->get('/releases', fn () => $releases->index());
$router->post('/releases', fn (Request $request) => $releases->store($request));

try {
    $router->dispatch($request)->send();
} catch (Throwable $exception) {
    Response::json([
        'error' => $exception->getMessage(),
    ], 500)->send();
}
