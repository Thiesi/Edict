<?php

declare(strict_types=1);

namespace Edict\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class HomeAction
{
    public function __construct(private Environment $twig) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('home.html.twig'));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
