<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\Session;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin confirms a sensitive change with the password and an authenticator
 * code, on top of the session, so a stolen session alone can't change how the
 * site is secured. Throttled like the login form; each code works once.
 */
final readonly class StepUp
{
    public function __construct(private AdminAccount $admin, private LoginThrottle $throttle) {}

    /**
     * @return 'ok'|'throttled'|'admin-not-confirmed'|'admin-cli' a notice key unless 'ok'
     */
    public function confirm(ServerRequestInterface $request, Session $session): string
    {
        $username = $this->admin->usernameOf($session->userId);
        if ($username === null) {
            return 'admin-cli';
        }
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($remote) ? $remote : 'unknown';
        if ($this->throttle->isBlocked($ip, $username)) {
            return 'throttled';
        }

        // Recorded before the slow check, so parallel requests can't all pass the limit.
        $this->throttle->recordFailure($ip, $username);
        $body = $request->getParsedBody();
        $verified = $this->admin->verify(
            $username,
            AdminAuthController::credential($body, 'password'),
            AdminAuthController::credential($body, 'code'),
        );
        if ($verified !== $session->userId) {
            return 'admin-not-confirmed';
        }
        $this->throttle->clear($ip, $username);

        return 'ok';
    }
}
