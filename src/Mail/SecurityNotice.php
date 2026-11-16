<?php

declare(strict_types=1);

namespace Edict\Mail;

use Edict\Clock;
use Edict\Config;
use Edict\Media\JobQueue;
use Edict\Site\Settings;

/**
 * Tells the sovereign when the way into the admin account changes: a login or
 * passkey added or removed, the notification address changed, a reset. Written
 * to the PHP error log at once, and mailed by the worker, whatever the comment
 * alert setting says.
 */
final readonly class SecurityNotice
{
    public const JOB = 'security-notice';

    public function __construct(
        private JobQueue $jobs,
        private Mailer $mailer,
        private Config $config,
        private Settings $settings,
        private Clock $clock,
    ) {}

    /**
     * @param string|null $alsoTo an address that should hear about it too, such as the one being replaced
     */
    public function record(string $what, ?string $alsoTo = null): void
    {
        $this->log($what);
        $this->jobs->enqueue(self::JOB, ['what' => $what, 'at' => $this->clock->now(), 'also_to' => $alsoTo]);
    }

    /**
     * Only the log line, for what the admin does on purpose to someone else's account,
     * such as deleting a reader: a mail about it would tell the admin what they just did.
     */
    public function log(string $what): void
    {
        if ($this->config->env !== 'test') {
            error_log('Edict security: ' . $what);
        }
    }

    /**
     * @param array<mixed> $payload
     */
    public function send(array $payload): void
    {
        $what = is_string($payload['what'] ?? null) ? $payload['what'] : '';
        $at = is_int($payload['at'] ?? null) ? $payload['at'] : $this->clock->now();
        $alsoTo = is_string($payload['also_to'] ?? null) ? $payload['also_to'] : null;
        if ($what === '' || !$this->mailer->isConfigured() && $alsoTo === null) {
            return;
        }
        $host = parse_url($this->config->appUrl, PHP_URL_HOST) ?: 'the site';

        $this->mailer->toOwner(
            '[' . $this->settings->site()->title . '] Security: ' . $what,
            implode("\n", [
                'On ' . gmdate('Y-m-d H:i', $at) . ' UTC: ' . $what . '.',
                '',
                'If that was you, there is nothing to do.',
                'If it wasn\'t, run "bin/edict admin:reset" on the host at once: it sets a new',
                'password and authenticator secret, removes all passkeys and ends every admin',
                'session. Then check the logins on ' . $this->config->appUrl . '/account.',
                '',
                '-- ',
                'Sent by Edict on ' . $host . ' for every change to how the admin signs in.',
            ]),
            $alsoTo,
        );
    }
}
