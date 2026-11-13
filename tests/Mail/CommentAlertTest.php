<?php

declare(strict_types=1);

namespace Edict\Tests\Mail;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Media\JobRunner;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;

/**
 * A stand-in sendmail records what Edict hands it, so nothing leaves the machine.
 */
final class CommentAlertTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    private string $dir;

    /** @var array<string, string> */
    private array $settings = [];

    private int $article;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The stand-in sendmail is a shell script');
        }
        $this->dir = sys_get_temp_dir() . '/edict-mail-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/sendmail', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$(dirname \"\$0\")/args\"\ncat > \"\$(dirname \"\$0\")/mail\"\n");
        file_put_contents($this->dir . '/failing', "#!/bin/sh\ncat > /dev/null\necho 'relay refused' >&2\nexit 75\n");
        chmod($this->dir . '/sendmail', 0o755);
        chmod($this->dir . '/failing', 0o755);
        $this->settings = ['ALERT_EMAIL' => 'sovereign@example.org', 'SENDMAIL_BIN' => $this->dir . '/sendmail'];
        parent::setUp();

        $stmt = $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'talk', 'en', ?, ?, 'published', to_timestamp(1), now(), now()) RETURNING id",
        );
        $stmt->execute(["Talk\r\nBcc: victim@example.org", Body::EMPTY]);
        $this->article = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            MediaStore::remove($this->dir);
        }
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, string> $settings
     */
    private function reboot(array $settings): void
    {
        $this->settings = $settings;
        $this->pdo->exec('TRUNCATE app_user CASCADE');
        parent::setUp();
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function signIn(int $userId): array
    {
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create($userId, 3600);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        return ['cookies' => [self::SESSION => $token], 'csrf' => $session->csrfToken];
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function reader(): array
    {
        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('Alice') RETURNING id");
        self::assertNotFalse($insert);

        return $this->signIn((int) $insert->fetchColumn());
    }

    /**
     * @param array{cookies: array<string, string>, csrf: string} $who
     */
    private function comment(array $who, string $body): void
    {
        $response = $this->post("/comments/{$this->article}", ['body' => $body, '_csrf' => $who['csrf']], $who['cookies']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
    }

    private function jobs(): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM job WHERE type = 'comment-alert'");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testANewCommentIsMailedToTheSovereign(): void
    {
        $this->comment($this->reader(), "Hello there,\nfine article.");
        self::assertSame(1, $this->jobs());

        self::assertTrue($this->service(JobRunner::class)->runNext(true), 'the second worker sends alerts too');

        $mail = (string) file_get_contents($this->dir . '/mail');
        self::assertStringContainsString("To: sovereign@example.org\r\n", $mail);
        self::assertStringContainsString("From: Edict <edict@localhost>\r\n", $mail);
        self::assertMatchesRegularExpression('~^Subject: =\?UTF-8\?Q\?.+$~m', $mail);
        self::assertStringNotContainsString("\nBcc:", $mail, 'a title is one line in a header');
        self::assertStringContainsString("Alice commented on \"Talk\r\nBcc: victim@example.org\":", str_replace("\r\n\r\n", "\r\n", $mail), 'the body may show it as it is');
        self::assertStringContainsString("Hello there,\r\nfine article.", $mail);
        self::assertMatchesRegularExpression('~See it, or remove it: http://localhost:8080/talk#comment-[0-9]+~', $mail);
        self::assertSame("-i\n-f\nedict@localhost\n--\nsovereign@example.org\n", file_get_contents($this->dir . '/args'), 'the recipient never comes from the headers');
    }

    public function testAHeldCommentSaysItWaits(): void
    {
        $this->pdo->exec("INSERT INTO setting (key, value) VALUES ('comment_default', '\"hold\"')");
        $this->comment($this->reader(), 'Let me in');
        $this->service(JobRunner::class)->runNext();

        $mail = (string) file_get_contents($this->dir . '/mail');
        self::assertStringContainsString('waits for your approval', $mail);
        self::assertStringContainsString('Approve or remove it:', $mail);
    }

    public function testNothingIsSentForTheSovereignsOwnOrRemovedComments(): void
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $this->comment($this->signIn((int) $id->fetchColumn()), 'My own');
        self::assertSame(0, $this->jobs());

        $this->comment($this->reader(), 'Gone soon');
        $this->pdo->exec("UPDATE comment SET state = 'removed'");
        $this->service(JobRunner::class)->runNext();
        self::assertFileDoesNotExist($this->dir . '/mail');
    }

    public function testWithoutAnAddressNothingIsSent(): void
    {
        $this->reboot(['SENDMAIL_BIN' => $this->dir . '/sendmail']);
        $this->pdo->exec("INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'quiet', 'en', 'Quiet', '{\"type\":\"doc\",\"content\":[]}', 'published', to_timestamp(1), now(), now())");
        $article = $this->pdo->query("SELECT id FROM article WHERE slug = 'quiet'");
        self::assertNotFalse($article);
        $this->article = (int) $article->fetchColumn();

        $this->comment($this->reader(), 'Unnoticed');
        $this->service(JobRunner::class)->runNext();

        self::assertFileDoesNotExist($this->dir . '/mail');
    }

    public function testARefusingRelayIsTriedAgain(): void
    {
        $this->reboot(['ALERT_EMAIL' => 'sovereign@example.org', 'SENDMAIL_BIN' => $this->dir . '/failing']);
        $this->pdo->exec("INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'retry', 'en', 'Retry', '{\"type\":\"doc\",\"content\":[]}', 'published', to_timestamp(1), now(), now())");
        $article = $this->pdo->query("SELECT id FROM article WHERE slug = 'retry'");
        self::assertNotFalse($article);
        $this->article = (int) $article->fetchColumn();

        $this->comment($this->reader(), 'Knock knock');
        $this->service(JobRunner::class)->runNext();

        $state = $this->pdo->query("SELECT state, error FROM job WHERE type = 'comment-alert'");
        self::assertNotFalse($state);
        $row = $state->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('queued', $row['state'], 'it runs again later');
        self::assertIsString($row['error']);
        self::assertStringContainsString('relay refused', $row['error']);
        $comment = $this->pdo->query('SELECT state FROM comment');
        self::assertNotFalse($comment);
        self::assertSame('visible', $comment->fetchColumn(), 'the comment is unaffected');
    }
}
