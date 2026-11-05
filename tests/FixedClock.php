<?php

declare(strict_types=1);

namespace Edict\Tests;

use Edict\Clock;

final class FixedClock implements Clock
{
    public function __construct(private int $time) {}

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
