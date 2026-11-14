<?php

declare(strict_types=1);

namespace Edict\Mail;

use Edict\Config;
use Edict\Database\Row;
use Edict\Media\Command;
use Edict\Media\MediaError;

/**
 * Plain-text mail through the host's sendmail, which hands it to the local MTA.
 * Only ever to the sovereign: Edict stores no reader addresses.
 */
final readonly class Mailer
{
    private const SECONDS = 30;

    public function __construct(private Config $config, private \PDO $pdo) {}

    /**
     * The admin's notification address from /account, or else ALERT_EMAIL.
     */
    public function recipient(): ?string
    {
        $stmt = $this->pdo->query('SELECT c.email FROM admin_credential c JOIN app_user u ON u.id = c.user_id WHERE u.is_admin AND c.email IS NOT NULL');
        $row = $stmt === false ? false : $stmt->fetch();

        return is_array($row) ? Row::str($row, 'email') : $this->config->alertEmail;
    }

    /**
     * A recipient and MAIL_FROM, or nothing is sent. There is deliberately no default
     * sender: a made-up one fails the domain's DMARC policy, and the mail vanishes unseen.
     */
    public function isConfigured(): bool
    {
        return $this->config->mailFrom !== null && $this->recipient() !== null;
    }

    /**
     * @param string|null $alsoTo a second address, such as the one being replaced
     *
     * @throws MediaError when sendmail refuses or isn't there, so the job tries again
     */
    public function toOwner(string $subject, string $text, ?string $alsoTo = null): void
    {
        $from = $this->config->mailFrom;
        if ($from === null) {
            return;
        }
        foreach (array_unique(array_filter([$this->recipient(), $alsoTo], static fn(?string $to): bool => $to !== null)) as $to) {
            $this->send($from, $to, $subject, $text);
        }
    }

    private function send(string $from, string $to, string $subject, string $text): void
    {
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
        // -f makes bounces go to the sender, which must be a mailbox that exists.
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
