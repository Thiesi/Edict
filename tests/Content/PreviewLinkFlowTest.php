<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Content\PreviewLinks;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Private preview links (#85). The test clock starts at 2027-01-15 09:00 Berlin time.
 */
final class PreviewLinkFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    /** @var array<string, string> */
    private array $admin;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');

        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function adminPost(string $path, array $fields = []): ResponseInterface
    {
        /** @var array<string, string> $fields */
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    /**
     * A saved draft, never published.
     *
     * @param array<string, mixed> $fields
     */
    private function draft(array $fields = []): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => 'article']);
        self::assertSame(303, $response->getStatusCode());
        $id = (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        $this->save($id, $fields);

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $id, array $fields = []): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $response = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => $fields + [
            'title' => 'Secret Plans',
            'slug' => '',
            'lang' => 'en',
            'teaser' => 'A teaser',
            'body' => json_decode(Doc::text('Words about zeppelins.'), true),
            'tags' => 'Airships',
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param string|null $at a datetime-local value, or null to publish now
     */
    private function publish(int $id, ?string $at = null): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $response = $this->adminPost("/admin/articles/$id/publish", ['version' => $version] + ($at === null ? [] : ['at' => $at]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @return array{path: string, expires: string, expiresIso: string}
     */
    private function share(int $id): array
    {
        $response = $this->adminPost("/admin/articles/$id/preview-link");
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsString($data['url'] ?? null);
        self::assertIsString($data['expires'] ?? null);
        self::assertIsString($data['expiresIso'] ?? null);
        self::assertMatchesRegularExpression('~^' . preg_quote(self::ORIGIN, '~') . '/preview/[A-Za-z0-9_-]{43}$~D', $data['url']);

        return ['path' => substr($data['url'], strlen(self::ORIGIN)), 'expires' => $data['expires'], 'expiresIso' => $data['expiresIso']];
    }

    private function assertGone(string $path, string $message = ''): void
    {
        $response = $this->get($path);
        self::assertSame(404, $response->getStatusCode(), $message);
        $body = (string) $response->getBody();
        self::assertStringContainsString('<h1>Not found</h1>', $body, $message);
        self::assertStringNotContainsString('zeppelins', $body, $message);
    }

    private function linkRows(): int
    {
        $stmt = $this->pdo->query('SELECT count(*) FROM preview_link');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testALinkShowsTheCurrentDraftToAnyone(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        // A week, in Berlin time: 2027-01-22 09:00.
        self::assertSame('22 Jan 2027, 09:00', $link['expires']);
        self::assertSame('2027-01-22T09:00:00+01:00', $link['expiresIso']);

        $page = $this->get($link['path']);
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString('<h1>Secret Plans</h1>', $html);
        self::assertStringContainsString('Words about zeppelins.', $html);
        self::assertStringContainsString('Preview, not published.', $html);
        self::assertStringNotContainsString('/admin/articles', $html, 'no way into the editor from here');

        // Later edits show up: it is the draft, not a copy.
        $this->save($id, ['title' => 'Revised Plans']);
        self::assertStringContainsString('<h1>Revised Plans</h1>', (string) $this->get($link['path'])->getBody());

        // And still nothing public.
        self::assertSame(404, $this->get('/secret-plans')->getStatusCode());
    }

    public function testThePageKeepsTheLinkPrivate(): void
    {
        $link = $this->share($this->draft());

        $page = $this->get($link['path']);
        self::assertSame('noindex', $page->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-referrer', $page->getHeaderLine('Referrer-Policy'));
        self::assertSame('private, no-store', $page->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $page->getHeaderLine('Content-Security-Policy'));
        $html = (string) $page->getBody();
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('/preview/', $html, 'the page never repeats its own address');

        // Other pages keep the default policy.
        self::assertSame('strict-origin-when-cross-origin', $this->get('/')->getHeaderLine('Referrer-Policy'));
    }

    public function testThePageHasNoCommentsOrLikesEvenSignedIn(): void
    {
        $link = $this->share($this->draft());

        foreach ([[], $this->admin] as $cookies) {
            $html = (string) $this->get($link['path'], $cookies)->getBody();
            self::assertStringContainsString('Words about zeppelins.', $html);
            self::assertStringNotContainsString('class="likes"', $html);
            self::assertStringNotContainsString('id="comments"', $html);
            self::assertStringNotContainsString('action="/comments/', $html);
            self::assertStringNotContainsString('action="/votes/', $html);
        }
    }

    public function testANewLinkReplacesTheOldOne(): void
    {
        $id = $this->draft();
        $first = $this->share($id);
        $second = $this->share($id);

        self::assertNotSame($first['path'], $second['path']);
        $this->assertGone($first['path']);
        self::assertSame(200, $this->get($second['path'])->getStatusCode());
        self::assertSame(1, $this->linkRows(), 'one link per piece');
    }

    public function testStopSharingEndsTheLink(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        $response = $this->adminPost("/admin/articles/$id/preview-link/stop");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['stopped' => true], json_decode((string) $response->getBody(), true));

        $this->assertGone($link['path']);
        self::assertSame(0, $this->linkRows());
    }

    public function testTheLinkExpiresAfterSevenDays(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        $this->clock->advance(PreviewLinks::DAYS * 86400 - 1);
        self::assertSame(200, $this->get($link['path'])->getStatusCode());
        self::assertStringContainsString('A link is shared until', (string) $this->get("/admin/articles/$id", $this->admin)->getBody());

        $this->clock->advance(1);
        $this->assertGone($link['path']);
        self::assertStringNotContainsString('A link is shared until', (string) $this->get("/admin/articles/$id", $this->admin)->getBody());

        // Making another clears the expired row away.
        $this->share($this->draft(['title' => 'Other Plans']));
        self::assertSame(1, $this->linkRows());
    }

    public function testPublishingEndsTheLink(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        $this->publish($id);

        $this->assertGone($link['path']);
        self::assertSame(200, $this->get('/secret-plans')->getStatusCode());
    }

    public function testSchedulingEndsTheLink(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        $this->publish($id, '2027-01-15T18:00');

        $this->assertGone($link['path']);
        self::assertSame(404, $this->get('/secret-plans')->getStatusCode(), 'not before its time');
    }

    public function testAPublishedPieceCanShareItsPendingChangesUntilUpdated(): void
    {
        $id = $this->draft();
        $this->publish($id);
        $this->save($id, ['body' => json_decode(Doc::text('Unreleased words.'), true)]);
        $link = $this->share($id);

        self::assertStringContainsString('Unreleased words.', (string) $this->get($link['path'])->getBody());
        self::assertStringNotContainsString('Unreleased words.', (string) $this->get('/secret-plans')->getBody());

        $this->publish($id);
        self::assertSame(404, $this->get($link['path'])->getStatusCode());
    }

    public function testTrashingEndsTheLinkAndRestoringDoesNotBringItBack(): void
    {
        $id = $this->draft();
        $link = $this->share($id);

        $this->adminPost("/admin/articles/$id/trash");
        $this->assertGone($link['path']);
        self::assertSame(0, $this->linkRows());

        $this->adminPost("/admin/articles/$id/restore");
        $this->assertGone($link['path']);

        // Nothing to share from the trash.
        $this->adminPost("/admin/articles/$id/trash");
        self::assertSame(409, $this->adminPost("/admin/articles/$id/preview-link")->getStatusCode());
        self::assertSame(0, $this->linkRows());
        self::assertStringNotContainsString('Share preview', (string) $this->get("/admin/articles/$id", $this->admin)->getBody());
    }

    public function testDeletingEndsTheLink(): void
    {
        $id = $this->draft();
        $link = $this->share($id);
        // In the trash by some other way than trash(), which ends the link itself.
        $this->pdo->prepare('UPDATE article SET deleted_at = to_timestamp(?) WHERE id = ?')->execute([$this->clock->now(), $id]);
        $this->assertGone($link['path'], 'a link never shows a piece in the trash');

        $this->adminPost("/admin/articles/$id/delete", ['confirm' => 'yes']);
        self::assertSame(0, $this->linkRows());
        $this->assertGone($link['path']);
    }

    public function testUnknownAndMalformedTokensAreTheOrdinary404(): void
    {
        $this->share($this->draft());

        $this->assertGone('/preview/' . str_repeat('A', 43));
        $this->assertGone('/preview/' . str_repeat('A', 42));
        $this->assertGone('/preview/not-a-token');
        $this->assertGone('/preview');

        // The same page as any other address that doesn't exist.
        self::assertSame((string) $this->get('/no-such-page')->getBody(), (string) $this->get('/preview/' . str_repeat('B', 43))->getBody());
    }

    public function testTheTokenIsStoredOnlyAsItsHash(): void
    {
        $link = $this->share($this->draft());
        $token = substr($link['path'], strlen('/preview/'));

        $stmt = $this->pdo->query('SELECT token_hash FROM preview_link');
        self::assertNotFalse($stmt);
        self::assertSame(hash('sha256', $token), $stmt->fetchColumn());
        $everything = $this->pdo->query("SELECT string_agg(p::text, ' ') FROM preview_link p");
        self::assertNotFalse($everything);
        self::assertStringNotContainsString($token, (string) $everything->fetchColumn());
    }

    public function testGuessingIsThrottledPerAddressWithTheSame404(): void
    {
        $link = $this->share($this->draft());
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(404, $this->get('/preview/' . str_repeat('C', 42) . chr(65 + $i))->getStatusCode());
        }

        // Even the right link is refused from that address now, and nothing says why.
        $this->assertGone($link['path']);
        self::assertTrue($this->service(LoginThrottle::class)->isPreviewBlocked('203.0.113.7'));
        self::assertFalse($this->service(LoginThrottle::class)->isOAuthBlocked('203.0.113.7'), 'its own bucket');

        $this->clock->advance(LoginThrottle::WINDOW + 1);
        self::assertSame(200, $this->get($link['path'])->getStatusCode());
    }

    public function testOnlyTheAdminCanShareOrStop(): void
    {
        $id = $this->draft();

        // Visitors go to the login page; a session without the token is refused.
        self::assertSame(303, $this->post("/admin/articles/$id/preview-link")->getStatusCode());
        self::assertSame(303, $this->post("/admin/articles/$id/preview-link/stop")->getStatusCode());
        self::assertSame(403, $this->post("/admin/articles/$id/preview-link", [], $this->admin)->getStatusCode());
        self::assertSame(403, $this->post("/admin/articles/$id/preview-link", ['_csrf' => 'wrong'], $this->admin)->getStatusCode());
        self::assertSame(0, $this->linkRows());

        // The editor's way: the token in a header.
        $response = $this->post("/admin/articles/$id/preview-link", [], $this->admin, ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(403, $this->post("/admin/articles/$id/preview-link/stop", [], $this->admin)->getStatusCode());
        self::assertSame(1, $this->linkRows());

        // A signed-in reader is not the admin.
        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $insert->fetchColumn(), 3600);
        $reader = $sessions->load($token);
        self::assertNotNull($reader);
        self::assertSame(403, $this->post("/admin/articles/$id/preview-link", ['_csrf' => $reader->csrfToken], [self::SESSION => $token])->getStatusCode());
        self::assertSame(403, $this->post("/admin/articles/$id/preview-link/stop", ['_csrf' => $reader->csrfToken], [self::SESSION => $token])->getStatusCode());
        self::assertSame(1, $this->linkRows());

        self::assertSame(404, $this->adminPost('/admin/articles/999/preview-link')->getStatusCode());
    }

    public function testTheEditorOffersSharing(): void
    {
        $id = $this->draft();

        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('id="share-create">Share preview</button>', $editor);
        self::assertStringContainsString('id="share-stop" hidden>', $editor);

        $this->share($id);
        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('A link is shared until <time datetime="2027-01-22T09:00:00+01:00">22 Jan 2027, 09:00</time> (Berlin time).', $editor);
        self::assertStringContainsString('id="share-create">New link</button>', $editor);
        self::assertStringContainsString('id="share-stop">Stop sharing</button>', $editor);
        self::assertStringNotContainsString('/preview/', $editor, 'the link is shown once, when made');
    }
}
