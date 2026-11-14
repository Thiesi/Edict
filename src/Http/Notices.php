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
        'taken' => 'That login already belongs to another account. To bring the two together, use “Combine with another account” below.',
        'combined' => 'Done: the two accounts are now one. Comments, likes and logins of both are here, under the name of the older account.',
        'combine-conflict' => 'Both accounts have a login with the same provider, so they can’t be combined. Remove that login from one of them first, or delete the account you no longer need.',
        'combine-admin' => 'The admin account can’t be combined with another account.',
        'unlinked' => 'The login was removed.',
        'not-linked' => 'That login isn’t on this account.',
        'last-login' => 'That is the only login of this account, so it can’t be removed.',
        'provider-in-use' => 'This account already has a login with that provider.',
        'admin-cli' => 'Change the admin’s logins in the form below, with your password and authenticator code.',
        'admin-not-confirmed' => 'That password or code is not valid, so nothing was changed. Each code works only once, so you may need to wait for the next one.',
        'admin-unlinked' => 'The login was removed, and every other admin session has ended.',
        'admin-email-saved' => 'The notification address was saved.',
        'admin-email-invalid' => 'That is not a usable email address, so nothing was changed.',
        'passkey-renamed' => 'The passkey was renamed.',
        'passkey-bad-name' => 'Please give the passkey a name of 1 to 64 characters.',
        'admin-moved' => 'The login was added. It belonged to a reader account until now; that account lost it, and was deleted if it had no other login.',
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
