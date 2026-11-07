<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * A media operation was refused or failed. The message is safe to show to the admin.
 */
final class MediaError extends \RuntimeException
{
    /**
     * @param bool $hopeless trying again can't help, which matters when a try takes hours
     */
    public function __construct(string $message, public readonly bool $hopeless = false)
    {
        parent::__construct($message);
    }
}
