<?php

declare(strict_types=1);

namespace Edict\Backup;

/**
 * A restore that didn't happen. Nothing was written; the message says why.
 */
final class RestoreRefused extends \RuntimeException {}
