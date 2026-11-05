<?php

declare(strict_types=1);

use Edict\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    // PHP-DI injects factory parameters by type.
    Environment::class => static function (Config $config): Environment {
        $cache = $config->cacheDir();

        return new Environment(new FilesystemLoader($config->projectDir . '/templates'), [
            'cache' => $cache === null ? false : $cache . '/twig',
            'debug' => $config->debug,
            'strict_variables' => $config->debug,
            'autoescape' => 'html',
        ]);
    },
];
