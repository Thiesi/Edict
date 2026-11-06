<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

/**
 * A login attempt failed at the provider or its answer was not acceptable. The
 * message goes to the log, so it must never contain secrets, codes or tokens.
 */
final class OAuthException extends \RuntimeException {}
