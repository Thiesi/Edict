<?php

declare(strict_types=1);

use Edict\Http\AccountController;
use Edict\Http\AdminAuthController;
use Edict\Http\ArticleAdminController;
use Edict\Http\HealthAction;
use Edict\Http\Middleware\RequireAdmin;
use Edict\Http\Middleware\RequireLogin;
use Edict\Http\OAuthController;
use Edict\Http\PasskeyController;
use Edict\Http\PublicController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [PublicController::class, 'home']);
    $app->get('/archive', [PublicController::class, 'archive']);
    $app->get('/tag/{slug:[a-z0-9-]+}', [PublicController::class, 'tag']);
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
    $app->post('/admin/passkey/options', [PasskeyController::class, 'loginOptions']);
    $app->post('/admin/passkey/login', [PasskeyController::class, 'login']);

    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', [AdminAuthController::class, 'dashboard']);
        $group->post('/logout', [AdminAuthController::class, 'logout']);
        $group->post('/logout-everywhere', [AdminAuthController::class, 'logoutEverywhere']);
        $group->get('/passkeys', [PasskeyController::class, 'manage']);
        $group->post('/passkeys', [PasskeyController::class, 'register']);
        $group->post('/passkeys/options', [PasskeyController::class, 'registerOptions']);
        $group->post('/passkeys/{id}/remove', [PasskeyController::class, 'remove']);

        $group->get('/csrf', [ArticleAdminController::class, 'csrf']);
        $group->get('/articles', [ArticleAdminController::class, 'index']);
        $group->post('/articles', [ArticleAdminController::class, 'create']);
        $group->get('/articles/{id:[0-9]+}', [ArticleAdminController::class, 'edit']);
        $group->post('/articles/{id:[0-9]+}/autosave', [ArticleAdminController::class, 'autosave']);
        $group->get('/articles/{id:[0-9]+}/preview', [ArticleAdminController::class, 'preview']);
        $group->post('/articles/{id:[0-9]+}/publish', [ArticleAdminController::class, 'publish']);
        $group->post('/articles/{id:[0-9]+}/unpublish', [ArticleAdminController::class, 'unpublish']);
        $group->post('/articles/{id:[0-9]+}/trash', [ArticleAdminController::class, 'trash']);
        $group->post('/articles/{id:[0-9]+}/restore', [ArticleAdminController::class, 'restore']);
        $group->post('/articles/{id:[0-9]+}/delete', [ArticleAdminController::class, 'delete']);
    })->add(RequireAdmin::class);

    // Last, and only for valid slugs: anything else never reaches the database.
    $app->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PublicController::class, 'article']);
};
