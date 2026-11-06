<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * A media operation was refused or failed. The message is safe to show to the admin.
 */
final class MediaError extends \RuntimeException {}
