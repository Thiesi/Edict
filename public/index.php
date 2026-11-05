<?php

declare(strict_types=1);

use Edict\Kernel;

// Built-in dev server: let it serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
    if (is_string($path) && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

Kernel::boot(dirname(__DIR__))->run();
