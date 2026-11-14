<?php

declare(strict_types=1);

namespace Edict\Tests\Mail;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Auth\Totp;
use Edict\Content\Body;
use Edict\Media\JobRunner;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The admin's notification address, the comment alert switch and the security
 * notices. A stand-in sendmail appends what it gets, so nothing leaves the machine.
 */
final class AdminEmailTest extends DbTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const SESSION = 'edict_session';

    private string $dir;
    private string $secret;

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The stand-in sendmail is a shell script');
        }
        $this->dir = sys_get_temp_dir() . '/edict-mail-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        // One line per mail: the recipient, then the subject. A long subject is folded
        // over several header lines, so awk joins them again.
        file_put_contents($this->dir . '/sendmail', <<<'SH'
            #!/bin/sh
            for last; do :; done
            subject=$(awk '{ sub(/\r$/, "") } /^$/ { exit } /^Subject:/ { s = $0; f = 1; next } f && /^[ \t]/ { s = s $0; next } { f = 0 } END { print s }')
            printf '%s | %s\n' "$last" "$subject" >> "$(dirname "$0")/sent"

            SH);
        chmod($this->dir . '/sendmail', 0o755);
        parent::setUp();
        $this->secret = $this->service(AdminAccount::class)->create('sovereign', self::PASSWORD);
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
        return ['ALERT_EMAIL' => 'fallback@example.org', 'MAIL_FROM' => 'edict@example.net', 'SENDMAIL_BIN' => $this->dir . '/sendmail'];
    }

    private function code(): string
    {
        $code = Totp::code($this->secret, intdiv($this->clock->now(), 30));
        $this->clock->advance(31);

        return $code;
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function admin(): array
    {
        $response = $this->post('/admin/login', ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()]);
        $token = '';
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . self::SESSION . '=([^;]+)/', $header, $match) === 1) {
                $token = $match[1];
            }
        }
        $cookies = [self::SESSION => $token];
        $session = $this->service(SessionStore::class)->load($token);
        self::assertNotNull($session);

        return ['cookies' => $cookies, 'csrf' => $session->csrfToken];
    }

    /**
     * @param array{cookies: array<string, string>, csrf: string} $admin
     */
    private function setAddress(array $admin, string $email, ?string $code = null): ResponseInterface
    {
        return $this->post('/account/admin-email', [
            '_csrf' => $admin['csrf'], 'email' => $email, 'password' => self::PASSWORD, 'code' => $code ?? $this->code(),
        ], $admin['cookies']);
    }

    private function runJobs(): void
    {
        while ($this->service(JobRunner::class)->runNext(true)) {
            // Until the queue is empty.
        }
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        $file = $this->dir . '/sent';

        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    }

    private function comment(): void
    {
        $this->pdo->exec("INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'talk', 'en', 'Talk', '" . Body::EMPTY . "', 'published', to_timestamp(1), now(), now())");
        $user = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('Alice') RETURNING id");
        self::assertNotFalse($user);
        $this->pdo->exec("INSERT INTO comment (article_id, user_id, body, state, created_at)
             SELECT id, {$user->fetchColumn()}, 'Hello', 'visible', now() FROM article WHERE slug = 'talk'");
        $this->pdo->exec("INSERT INTO job (type, payload, run_after, created_at)
             SELECT 'comment-alert', jsonb_build_object('comment_id', id), now(), now() FROM comment");
    }

    public function testTheAddressNeedsThePasswordAndCodeAndMustBeAnAddress(): void
    {
        $admin = $this->admin();
        $page = (string) $this->get('/account', $admin['cookies'])->getBody();
        self::assertStringContainsString('action="/account/admin-email"', $page);

        self::assertSame('/account?notice=admin-not-confirmed', $this->setAddress($admin, 'me@example.org', '000000')->getHeaderLine('Location'));
        self::assertSame('/account?notice=admin-email-invalid', $this->setAddress($admin, 'not an address')->getHeaderLine('Location'));
        self::assertNull($this->service(AdminAccount::class)->email());

        self::assertSame('/account?notice=admin-email-saved', $this->setAddress($admin, ' me@example.org ')->getHeaderLine('Location'));
        self::assertSame('me@example.org', $this->service(AdminAccount::class)->email());
        self::assertStringContainsString('value="me@example.org"', (string) $this->get('/account', $admin['cookies'])->getBody());

        $this->setAddress($admin, '');
        self::assertNull($this->service(AdminAccount::class)->email());
    }

    public function testChangingTheAddressTellsTheOldOneToo(): void
    {
        $admin = $this->admin();
        $this->setAddress($admin, 'old@example.org');
        $this->runJobs();
        $this->setAddress($admin, 'new@example.org');
        $this->runJobs();

        self::assertSame([
            'old@example.org | Subject: [Edict] Security: The admin notification address was changed to old@example.org',
            'new@example.org | Subject: [Edict] Security: The admin notification address was changed to new@example.org',
            'old@example.org | Subject: [Edict] Security: The admin notification address was changed to new@example.org',
        ], $this->sent());
    }

    public function testCommentAlertsGoToTheAdminsAddressAndCanBeSwitchedOff(): void
    {
        $this->service(AdminAccount::class)->setEmail('me@example.org');
        $this->comment();
        $this->runJobs();
        self::assertSame(['me@example.org | Subject: [Edict] New comment on "Talk"'], $this->sent());

        $admin = $this->admin();
        $page = (string) $this->get('/admin/comments', $admin['cookies'])->getBody();
        self::assertStringContainsString('name="comment_alerts" value="1" checked', $page);
        self::assertStringContainsString('They go to me@example.org.', $page);

        $saved = $this->post('/admin/comments/rules', ['_csrf' => $admin['csrf'], 'comment_default' => 'open', 'comment_word_action' => 'hold'], $admin['cookies']);
        self::assertSame('/admin/comments?saved=1', $saved->getHeaderLine('Location'));
        self::assertStringNotContainsString('name="comment_alerts" value="1" checked', (string) $this->get('/admin/comments', $admin['cookies'])->getBody());

        $this->pdo->exec("INSERT INTO job (type, payload, run_after, created_at)
             SELECT 'comment-alert', jsonb_build_object('comment_id', id), now(), now() FROM comment");
        $this->runJobs();
        self::assertCount(1, $this->sent(), 'switched off: nothing more');
    }

    public function testLoginAndPasskeyChangesAreReported(): void
    {
        $this->service(AdminAccount::class)->setEmail('me@example.org');
        $this->service(Accounts::class)->allowForAdmin('google', 'owner');
        $admin = $this->admin();

        $removed = $this->post('/account/admin-logins', [
            '_csrf' => $admin['csrf'], 'password' => self::PASSWORD, 'code' => $this->code(), 'action' => 'remove:google',
        ], $admin['cookies']);
        self::assertSame('/account?notice=admin-unlinked', $removed->getHeaderLine('Location'));

        $this->pdo->exec("INSERT INTO passkey (user_id, credential_id, public_key, name, sign_count, created_at)
             SELECT id, 'cred', 'key', 'Laptop', 0, now() FROM app_user WHERE is_admin");
        $id = $this->pdo->query('SELECT id FROM passkey');
        self::assertNotFalse($id);
        $this->post('/admin/passkeys/' . $id->fetchColumn() . '/remove', ['_csrf' => $admin['csrf']], $admin['cookies']);
        $this->runJobs();

        self::assertSame([
            "me@example.org | Subject: [Edict] Security: The admin's Google login was removed, and every other admin session ended",
            'me@example.org | Subject: [Edict] Security: The passkey named "Laptop" was removed from the admin account',
        ], $this->sent());
    }

    public function testWithoutAnAddressTheFallbackApplies(): void
    {
        $this->comment();
        $this->runJobs();

        self::assertSame(['fallback@example.org | Subject: [Edict] New comment on "Talk"'], $this->sent());
    }
}
