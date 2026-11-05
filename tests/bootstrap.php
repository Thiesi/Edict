<?php

declare(strict_types=1);

use Edict\Tests\ScratchGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

$error = ScratchGuard::check(dirname(__DIR__), ScratchGuard::limitFromEnv());
if ($error !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(2);
}
