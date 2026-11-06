<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Edict\Tests\ScratchGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

// Makes TEST_DATABASE_URL from .env visible to getenv(); real environment variables win.
Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();

$error = ScratchGuard::check(dirname(__DIR__), ScratchGuard::limitFromEnv());
if ($error !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(2);
}
