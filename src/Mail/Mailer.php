<?php

declare(strict_types=1);

namespace Edict\Mail;

use Edict\Config;
use Edict\Media\Command;
use Edict\Media\MediaError;

/**
 * Plain-text mail through the host's sendmail, which hands it to the local MTA.
 * Only ever to the sovereign's own address: Edict stores no reader addresses.
 */
final readonly class Mailer
{
    private const SECONDS = 30;

    public function __construct(private Config $config) {}

    public function isConfigured(): bool
    {
        return $this->config->alertEmail !== null;
    }

    /**
     * @throws MediaError when sendmail refuses or isn't there, so the job tries again
     */
    public function toOwner(string $subject, string $text): void
    {
        $to = $this->config->alertEmail;
        if ($to === null) {
            return;
        }
        $from = $this->config->mailFrom ?? 'edict@' . (parse_url($this->config->appUrl, PHP_URL_HOST) ?: 'localhost');

        $message = implode("\r\n", [
            'From: Edict <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader(self::oneLine($subject), 'UTF-8', 'Q', "\r\n"),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr($from, strpos($from, '@') + 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
            '',
            str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text)),
        ]);

        // -t would trust addresses in the headers; the recipient is given here instead.
        Command::run([$this->config->sendmailBin, '-i', '-f', $from, '--', $to], self::SECONDS, sys_get_temp_dir(), $message);
    }

    /**
     * A header is one line, whatever a title or name contains.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('~[\r\n\t]+~', ' ', $text));
    }
}
