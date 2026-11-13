<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Services;
use Slim\Error\Renderers\HtmlErrorRenderer;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
use Twig\Environment;

/**
 * Error pages in the site's own look. If the theme can't be drawn, because the
 * database is the problem for instance, a plain page says the same thing.
 */
final readonly class ErrorPage implements ErrorRendererInterface
{
    public function __construct(private Services $services) {}

    public function __invoke(\Throwable $exception, bool $displayErrorDetails): string
    {
        // Developers want the stack trace, not a pretty page.
        if ($displayErrorDetails) {
            return (new HtmlErrorRenderer())($exception, true);
        }

        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        [$title, $text] = match ($status) {
            404 => ['Not found', 'There is nothing at this address. It may have moved, or never existed.'],
            403 => ['Not allowed', 'This page isn’t open to you.'],
            405 => ['Not like this', 'This address doesn’t take that kind of request.'],
            default => ['Something went wrong', 'The site ran into a problem. Please try again in a moment.'],
        };

        try {
            return $this->services->get(Environment::class)->render('public/error.html.twig', ['status' => $status, 'title' => $title, 'text' => $text]);
        } catch (\Throwable) {
            return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . $title . '</title></head><body style="font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem">'
                . '<h1>' . $title . '</h1><p>' . $text . '</p><p><a href="/">To the home page</a></p></body></html>';
        }
    }
}
