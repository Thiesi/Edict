<?php

declare(strict_types=1);

use Edict\Http\AccountController;
use Edict\Http\AdminAuthController;
use Edict\Http\HealthAction;
use Edict\Http\HomeAction;
use Edict\Http\Middleware\RequireAdmin;
use Edict\Http\Middleware\RequireLogin;
use Edict\Http\OAuthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', HomeAction::class);
    $app->get('/healthz', HealthAction::class);

    $app->get('/login', [OAuthController::class, 'showLogin']);
    $app->get('/auth/{provider}', [OAuthController::class, 'start']);
    $app->get('/auth/{provider}/callback', [OAuthController::class, 'callback']);

    // Not static: Slim binds group callables to the container.
    $app->group('/account', function (RouteCollectorProxy $group): void {
        $group->get('', [AccountController::class, 'show']);
        $group->post('/name', [AccountController::class, 'rename']);
        $group->post('/link/{provider}', [OAuthController::class, 'startLink']);
        $group->post('/logout', [AccountController::class, 'logout']);
        $group->post('/logout-everywhere', [AccountController::class, 'logoutEverywhere']);
        $group->post('/delete', [AccountController::class, 'delete']);
    })->add(RequireLogin::class);

    $app->get('/admin/login', [AdminAuthController::class, 'showLogin']);
    $app->post('/admin/login', [AdminAuthController::class, 'login']);

    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', [AdminAuthController::class, 'dashboard']);
        $group->post('/logout', [AdminAuthController::class, 'logout']);
        $group->post('/logout-everywhere', [AdminAuthController::class, 'logoutEverywhere']);
    })->add(RequireAdmin::class);
};
