<?php

declare(strict_types=1);

namespace Edict;

use DI\Container;

/**
 * Typed, on-demand service lookup. For the few places that must not build their
 * dependencies up front, e.g. middleware and CLI commands that would otherwise
 * open a database connection they may never need.
 */
final readonly class Services
{
    public function __construct(private Container $container) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException("Container entry $class has the wrong type");
        }

        return $service;
    }
}
