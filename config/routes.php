<?php

declare(strict_types=1);

use Edict\Http\HealthAction;
use Edict\Http\HomeAction;
use Slim\App;

return static function (App $app): void {
    $app->get('/', HomeAction::class);
    $app->get('/healthz', HealthAction::class);
};
