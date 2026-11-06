<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class HomeAction
{
    public function __construct(private Environment $twig) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        $response->getBody()->write($this->twig->render('home.html.twig', [
            'signed_in' => $session instanceof Session,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
