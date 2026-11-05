<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

use Edict\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class AppTestCase extends TestCase
{
    /**
     * @param array<string, string> $env
     */
    protected function get(string $path, array $env = []): ResponseInterface
    {
        $app = Kernel::boot(dirname(__DIR__, 2), $env + ['APP_ENV' => 'test']);

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }
}
