<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * The draft changed after it was read, so publishing it would lose the newer text.
 */
final class StaleDraft extends \RuntimeException {}
