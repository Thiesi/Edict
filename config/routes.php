<?php

declare(strict_types=1);

use Edict\Http\AccountController;
use Edict\Http\AdminAuthController;
use Edict\Http\ArticleAdminController;
use Edict\Http\CommentController;
use Edict\Http\FeedController;
use Edict\Http\HealthAction;
use Edict\Http\MediaController;
use Edict\Http\Middleware\RequireAdmin;
use Edict\Http\Middleware\RequireLogin;
use Edict\Http\OAuthController;
use Edict\Http\PasskeyController;
use Edict\Http\PublicController;
use Edict\Http\SiteController;
use Edict\Http\VoteController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [PublicController::class, 'home']);
    $app->get('/archive', [PublicController::class, 'archive']);
    $app->get('/search', [PublicController::class, 'search']);
    $app->get('/feed.atom', [FeedController::class, 'site']);
    $app->get('/tag/{slug:[a-z0-9-]+}/feed.atom', [FeedController::class, 'tag']);
    $app->get('/sitemap.xml', [FeedController::class, 'sitemap']);
    $app->get('/robots.txt', [FeedController::class, 'robots']);
    $app->get('/tag/{slug:[a-z0-9-]+}', [PublicController::class, 'tag']);
    $app->get('/healthz', HealthAction::class);

    $app->post('/votes/{id:[0-9]+}', [VoteController::class, 'like']);
    $app->post('/comments/{id:[0-9]+}', [CommentController::class, 'post']);

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

        $group->post('/uploads', [MediaController::class, 'begin']);
        $group->get('/uploads/{id}', [MediaController::class, 'status']);
        $group->put('/uploads/{id}', [MediaController::class, 'chunk']);
        $group->post('/uploads/{id}/complete', [MediaController::class, 'complete']);
        $group->delete('/uploads/{id}', [MediaController::class, 'abort']);
        $group->get('/media', [MediaController::class, 'index']);
        $group->get('/media/{id:[0-9]+}', [MediaController::class, 'show']);
        $group->post('/media/{id:[0-9]+}/poster', [MediaController::class, 'poster']);

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
        $group->post('/articles/{id:[0-9]+}/revisions/{revision:[0-9]+}/restore', [ArticleAdminController::class, 'restoreRevision']);
        $group->get('/export', [ArticleAdminController::class, 'export']);
        $group->get('/tags', [ArticleAdminController::class, 'tags']);
        $group->post('/tags/{slug:[a-z0-9-]+}', [ArticleAdminController::class, 'renameTag']);
        $group->post('/articles/{id:[0-9]+}/votes', [VoteController::class, 'adjust']);

        $group->get('/comments', [CommentController::class, 'queue']);
        $group->post('/comments/rules', [CommentController::class, 'saveRules']);
        $group->post('/comments/{id:[0-9]+}/{action:[a-z-]+}', [CommentController::class, 'moderate']);
        $group->post('/articles/{id:[0-9]+}/comments', [CommentController::class, 'mode']);
        $group->post('/users/{id:[0-9]+}/unban', [CommentController::class, 'unban']);

        $group->get('/themes', [SiteController::class, 'themes']);
        $group->post('/themes', [SiteController::class, 'applyTheme']);
        $group->get('/site', [SiteController::class, 'show']);
        $group->post('/site', [SiteController::class, 'save']);
        $group->post('/site/legal/{which:imprint|privacy}', [SiteController::class, 'legalDraft']);
    })->add(RequireAdmin::class);

    // Apache serves /media/ directly when deployed. This is for local development.
    $app->get('/media/{path:.+}', [MediaController::class, 'serve']);

    // Last, and only for valid slugs: anything else never reaches the database.
    $app->get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [PublicController::class, 'article']);
};
