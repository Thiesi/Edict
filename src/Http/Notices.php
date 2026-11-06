<?php

declare(strict_types=1);

namespace Edict\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Messages shown after a redirect. The URL carries only a key, so a crafted link
 * cannot put arbitrary text on our pages.
 */
final class Notices
{
    private const TEXTS = [
        'cancelled' => 'Sign-in was cancelled.',
        'expired' => 'That sign-in attempt has expired. Please try again.',
        'provider-error' => 'The provider could not complete the sign-in. Please try again.',
        'throttled' => 'Too many failed attempts. Please try again later.',
        'welcome' => 'Welcome! Your account is ready. Please check the name below: it is shown publicly next to your comments.',
        'renamed' => 'Your name was changed.',
        'invalid-name' => 'Please enter a name of 1 to 64 characters.',
        'linked' => 'The login was added to your account.',
        'already' => 'That login already belongs to this account.',
        'taken' => 'That login already belongs to another account. Accounts are never merged, so delete the other account first if you want to move it.',
        'provider-in-use' => 'This account already has a login with that provider.',
        'admin-cli' => 'Logins for the admin account are managed on the command line.',
        'confirm-delete' => 'Please tick the box to confirm the deletion.',
    ];

    public static function fromRequest(ServerRequestInterface $request): ?string
    {
        $key = $request->getQueryParams()['notice'] ?? null;

        return is_string($key) ? self::text($key) : null;
    }

    public static function text(string $key): ?string
    {
        return self::TEXTS[$key] ?? null;
    }
}
