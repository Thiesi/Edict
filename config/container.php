<?php

declare(strict_types=1);

use Edict\Auth\OAuth\HttpClient;
use Edict\Auth\OAuth\StreamHttpClient;
use Edict\Auth\SecretBox;
use Edict\Clock;
use Edict\Config;
use Edict\Content\ArticleDate;
use Edict\Content\Body;
use Edict\Database\ConnectionFactory;
use Edict\Database\Migrator;
use Edict\SystemClock;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

return [
    Clock::class => DI\autowire(SystemClock::class),

    HttpClient::class => DI\autowire(StreamHttpClient::class),

    ResponseFactoryInterface::class => DI\autowire(ResponseFactory::class),

    // Resolved lazily: requests without a session cookie never open a connection.
    PDO::class => static function (Config $config): PDO {
        if ($config->databaseUrl === null) {
            throw new RuntimeException('DATABASE_URL is not configured');
        }

        return ConnectionFactory::fromUrl($config->databaseUrl);
    },

    SecretBox::class => static fn(Config $config): SecretBox => new SecretBox($config->appSecret),

    Migrator::class => static fn(PDO $pdo, Config $config): Migrator => new Migrator($pdo, $config->projectDir . '/migrations'),

    // PHP-DI injects factory parameters by type.
    // The explicit root path keeps Twig's cache keys independent of the working
    // directory, which differs between the deploy (cache:warmup) and php-fpm.
    Environment::class => static function (Config $config): Environment {
        $cache = $config->cacheDir();

        $twig = new Environment(new FilesystemLoader($config->projectDir . '/templates', $config->projectDir), [
            'cache' => $cache === null ? false : $cache . '/twig',
            'debug' => $config->debug,
            'strict_variables' => $config->debug,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('tested_commit', $config->testedCommit());
        $twig->addFilter(new TwigFilter('article_date', ArticleDate::format(...)));
        $twig->addFilter(new TwigFilter('article_iso', ArticleDate::iso(...)));
        // Safe because Body::toHtml() is an allow-list that escapes all text.
        $twig->addFilter(new TwigFilter('body_html', Body::toHtml(...), ['is_safe' => ['html']]));

        return $twig;
    },
];
