<?php

declare(strict_types=1);

use Edict\Auth\OAuth\HttpClient;
use Edict\Auth\OAuth\StreamHttpClient;
use Edict\Auth\SecretBox;
use Edict\Clock;
use Edict\Config;
use Edict\Content\Article;
use Edict\Content\ArticleDate;
use Edict\Content\BodyRenderer;
use Edict\Content\CommentText;
use Edict\Database\ConnectionFactory;
use Edict\Database\Migrator;
use Edict\Services;
use Edict\Site\Settings;
use Edict\Site\SiteSettings;
use Edict\Site\Themes;
use Edict\SystemClock;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
    Environment::class => static function (Config $config, Services $services): Environment {
        $cache = $config->cacheDir();

        $twig = new Environment(new FilesystemLoader($config->projectDir . '/templates', $config->projectDir), [
            'cache' => $cache === null ? false : $cache . '/twig',
            'debug' => $config->debug,
            'strict_variables' => $config->debug,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('tested_commit', $config->testedCommit());
        $twig->addGlobal('asset_version', $config->release());
        // The theme, name, menu and footer. Read only by pages that show them.
        $twig->addFunction(new TwigFunction('site', static fn(): SiteSettings => $services->get(Settings::class)->site()));
        $twig->addFunction(new TwigFunction('theme_name', static fn(string $id): string => Themes::ALL[$id]['name'] ?? $id));
        $twig->addFilter(new TwigFilter('article_date', ArticleDate::format(...)));
        $twig->addFilter(new TwigFilter('article_iso', ArticleDate::iso(...)));
        // Safe: CommentText escapes everything and adds only <p>, <br> and links.
        $twig->addFilter(new TwigFilter('comment_html', CommentText::html(...), ['is_safe' => ['html']]));
        // Safe because BodyRenderer and Body build HTML from fixed tags and escape all values.
        // Resolved only when used, so pages without an article open no database connection.
        $twig->addFilter(new TwigFilter(
            'body_html',
            static fn(string $json): string => $services->get(BodyRenderer::class)->html($json),
            ['is_safe' => ['html']],
        ));
        $twig->addFunction(new TwigFunction(
            'article_cover',
            static fn(Article $article, bool $inListing = false): string => $services->get(BodyRenderer::class)->cover($article, $inListing),
            ['is_safe' => ['html']],
        ));

        return $twig;
    },
];
