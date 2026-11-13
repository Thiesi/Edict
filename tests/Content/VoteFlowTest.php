<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class VoteFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    private function article(string $slug = 'liked', string $kind = 'article', string $status = 'published'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES (?, ?, 'en', 'Liked', ?, ?, to_timestamp(1), now(), now()) RETURNING id",
        );
        $stmt->execute([$kind, $slug, Body::EMPTY, $status]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function reader(string $name = 'reader'): array
    {
        $insert = $this->pdo->prepare('INSERT INTO app_user (display_name) VALUES (?) RETURNING id');
        $insert->execute([$name]);

        return $this->signIn((int) $insert->fetchColumn());
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function admin(): array
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);

        return $this->signIn((int) $id->fetchColumn());
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function signIn(int $userId): array
    {
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create($userId, 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        return ['id' => $userId, 'cookies' => [self::SESSION => $token], 'csrf' => $session->csrfToken];
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string} $who
     * @param array<string, string> $fields
     */
    private function vote(int $articleId, array $who, array $fields = ['like' => '1'], bool $json = false): ResponseInterface
    {
        return $this->post("/votes/$articleId", $fields + ['_csrf' => $who['csrf']], $who['cookies'], $json ? ['Accept' => 'application/json'] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testVisitorsSeeTheCountAndAWayToLogIn(): void
    {
        $id = $this->article();

        $page = (string) $this->get('/liked')->getBody();
        self::assertStringContainsString('♥ 0', $page);
        self::assertStringContainsString('href="/login?next=%2Fliked"', $page);
        self::assertStringNotContainsString('like-form', $page);
        self::assertStringNotContainsString('likes.js', $page);

        $response = $this->post("/votes/$id");
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?next=%2Fliked', $response->getHeaderLine('Location'));
    }

    public function testAReaderLikesOnceAndCanTakeItBack(): void
    {
        $id = $this->article();
        $reader = $this->reader();

        $response = $this->vote($id, $reader);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/liked#likes', $response->getHeaderLine('Location'));
        $this->vote($id, $reader);

        $page = $this->get('/liked', $reader['cookies']);
        $html = (string) $page->getBody();
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('1 like<', $html);
        self::assertStringContainsString('name="like" value="0"', $html, 'the button now takes it back');
        self::assertStringContainsString('/assets/likes.js', $html);
        self::assertSame('private, no-store', $page->getHeaderLine('Cache-Control'), 'a page with my own like is mine');

        self::assertSame(['real' => 0, 'adjustment' => 0, 'shown' => 0, 'mine' => false], self::json($this->vote($id, $reader, ['like' => '0'], json: true)));
    }

    public function testTheScriptGetsTheNewCount(): void
    {
        $id = $this->article();
        $this->vote($id, $this->reader('first'));

        $response = $this->vote($id, $this->reader('second'), json: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['real' => 2, 'adjustment' => 0, 'shown' => 2, 'mine' => true], self::json($response));
    }

    public function testLikingNeedsTheToken(): void
    {
        $id = $this->article();
        $reader = $this->reader();

        self::assertSame(403, $this->post("/votes/$id", ['like' => '1'], $reader['cookies'])->getStatusCode());
        self::assertSame(0, $this->votes());
    }

    public function testBannedReadersCannotLike(): void
    {
        $id = $this->article();
        $reader = $this->reader();
        $this->pdo->prepare("UPDATE app_user SET banned_until = now() + interval '100 years' WHERE id = ?")->execute([$reader['id']]);

        $response = $this->vote($id, $reader, json: true);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse(self::json($response)['mine']);
        self::assertSame(0, $this->votes());
        self::assertStringNotContainsString('like-form', (string) $this->get('/liked', $reader['cookies'])->getBody());
    }

    public function testOnlyPublicArticlesCanBeLiked(): void
    {
        $reader = $this->reader();

        self::assertSame(404, $this->vote($this->article('a-draft', status: 'draft'), $reader)->getStatusCode());
        self::assertSame(404, $this->vote(999_999, $reader)->getStatusCode());
        self::assertSame(0, $this->votes());
        $this->article('about', kind: 'page');
        self::assertStringNotContainsString('id="likes"', (string) $this->get('/about', $reader['cookies'])->getBody(), 'pages have no likes');
    }

    public function testTheSovereignAdjustsTheCount(): void
    {
        $id = $this->article();
        $this->vote($id, $this->reader());
        $admin = $this->admin();

        $set = $this->post("/admin/articles/$id/votes", ['adjustment' => '50', '_csrf' => $admin['csrf']], $admin['cookies']);
        self::assertSame(303, $set->getStatusCode());
        self::assertSame('/liked#likes', $set->getHeaderLine('Location'));
        $added = $this->post("/admin/articles/$id/votes", ['add' => '100', '_csrf' => $admin['csrf']], $admin['cookies'], ['Accept' => 'application/json']);
        self::assertSame(['real' => 1, 'adjustment' => 150, 'shown' => 151, 'mine' => false], self::json($added));

        $page = (string) $this->get('/liked', $admin['cookies'])->getBody();
        self::assertStringContainsString('151 likes', $page);
        self::assertStringContainsString('Real likes: <strong>1</strong> · adjustment: <strong>+150</strong>', $page);
        self::assertStringNotContainsString('Real likes', (string) $this->get('/liked')->getBody(), 'only the sovereign sees the truth');
        $list = (string) $this->get('/admin/articles', $admin['cookies'])->getBody();
        self::assertStringContainsString('151 <small>(1 real, +150)</small>', $list);

        $this->post("/admin/articles/$id/votes", ['adjustment' => '-1000', '_csrf' => $admin['csrf']], $admin['cookies']);
        self::assertStringContainsString('♥ 0', (string) $this->get('/liked')->getBody(), 'never below zero');
        self::assertSame(400, $this->post("/admin/articles/$id/votes", ['adjustment' => 'lots', '_csrf' => $admin['csrf']], $admin['cookies'])->getStatusCode());
    }

    public function testReadersCannotAdjust(): void
    {
        $id = $this->article();
        $reader = $this->reader();

        self::assertSame(403, $this->post("/admin/articles/$id/votes", ['add' => '100', '_csrf' => $reader['csrf']], $reader['cookies'])->getStatusCode());
    }

    public function testDeletingAnAccountRemovesItsLikes(): void
    {
        $id = $this->article();
        $reader = $this->reader();
        $this->vote($id, $reader);

        $this->service(Accounts::class)->delete($reader['id']);

        self::assertSame(0, $this->votes());
    }

    private function votes(): int
    {
        $stmt = $this->pdo->query('SELECT count(*) FROM vote');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
