<?php

declare(strict_types=1);

use Edict\Http\AdminAuthController;
use Edict\Http\HealthAction;
use Edict\Http\HomeAction;
use Edict\Http\Middleware\RequireAdmin;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', HomeAction::class);
    $app->get('/healthz', HealthAction::class);

    $app->get('/admin/login', [AdminAuthController::class, 'showLogin']);
    $app->post('/admin/login', [AdminAuthController::class, 'login']);

    // Not static: Slim binds group callables to the container.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', [AdminAuthController::class, 'dashboard']);
        $group->post('/logout', [AdminAuthController::class, 'logout']);
        $group->post('/logout-everywhere', [AdminAuthController::class, 'logoutEverywhere']);
    })->add(RequireAdmin::class);
};
