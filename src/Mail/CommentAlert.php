<?php

declare(strict_types=1);

namespace Edict\Mail;

use Edict\Config;
use Edict\Database\Row;
use Edict\Site\Settings;

/**
 * Tells the sovereign about a new comment, or one waiting for approval, so abuse
 * doesn't stay up until someone happens to look. Sent by the worker, never while
 * the reader waits.
 */
final readonly class CommentAlert
{
    public const JOB = 'comment-alert';

    public function __construct(private \PDO $pdo, private Mailer $mailer, private Config $config, private Settings $settings) {}

    public function send(int $commentId): void
    {
        if (!$this->mailer->isConfigured()) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "SELECT c.body, c.state, a.slug, a.title, coalesce(u.display_name, '[deleted user]') AS author
             FROM comment c JOIN article a ON a.id = c.article_id LEFT JOIN app_user u ON u.id = c.user_id
             WHERE c.id = ?",
        );
        $stmt->execute([$commentId]);
        $row = $stmt->fetch();
        // Removed in the meantime: nothing left to look at.
        if (!is_array($row) || Row::str($row, 'state') === 'removed') {
            return;
        }

        $held = Row::str($row, 'state') === 'held';
        $site = $this->settings->site()->title;
        $title = Row::str($row, 'title');
        $link = $this->config->appUrl . '/' . Row::str($row, 'slug') . '#comment-' . $commentId;

        $this->mailer->toOwner(
            '[' . $site . '] ' . ($held ? 'Waiting for approval' : 'New comment') . ' on "' . $title . '"',
            implode("\n", [
                Row::str($row, 'author') . ($held ? ' wrote a comment that waits for your approval' : ' commented') . ' on "' . $title . '":',
                '',
                Row::str($row, 'body'),
                '',
                ($held ? 'Approve or remove it: ' : 'See it, or remove it: ') . $link,
                'Everything waiting: ' . $this->config->appUrl . '/admin/comments',
                '',
                '-- ',
                'Sent by Edict because ALERT_EMAIL is set on ' . (parse_url($this->config->appUrl, PHP_URL_HOST) ?: 'this site') . '.',
            ]),
        );
    }
}
