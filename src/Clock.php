<?php

declare(strict_types=1);

namespace Edict;

/**
 * Current time as a Unix timestamp. Injected so that expiry, throttling and
 * TOTP logic can be tested without sleeping.
 */
interface Clock
{
    public function now(): int;
}
