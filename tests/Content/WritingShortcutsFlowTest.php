<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Tests\DbTestCase;

/**
 * The admin's ways to start writing from the live site, and from the editor to the
 * media library.
 */
final class WritingShortcutsFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function reader(): array
    {
        $insert = $this->pdo->prepare('INSERT INTO app_user (display_name) VALUES (?) RETURNING id');
        $insert->execute(['Reader']);

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

    private function publishedArticle(string $lang = 'en'): void
    {
        $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'out-there', ?, 'Out there', ?, 'published', to_timestamp(?), now(), now())",
        )->execute([$lang, Body::EMPTY, $this->clock->now() - 60]);
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string}|null $who
     */
    private function home(?array $who, string $path = '/'): string
    {
        return (string) $this->get($path, $who === null ? [] : $who['cookies'])->getBody();
    }

    /**
     * @return list<array{kind: string, status: string}>
     */
    private function rows(): array
    {
        $stmt = $this->pdo->query('SELECT kind, status FROM article ORDER BY id');
        self::assertNotFalse($stmt);

        /** @var list<array{kind: string, status: string}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testOnlyTheAdminGetsTheNewArticleAndNewPageForms(): void
    {
        $this->publishedArticle();
        $form = '<form class="nav-form" method="post" action="/admin/articles">';

        foreach ([null, $this->reader()] as $who) {
            foreach (['/', '/out-there', '/archive'] as $path) {
                $html = $this->home($who, $path);
                self::assertStringNotContainsString($form, $html, $path);
                self::assertStringNotContainsString('New article', $html, $path);
            }
        }

        $admin = $this->admin();
        foreach (['/', '/out-there', '/archive', '/search?q=out'] as $path) {
            $response = $this->get($path, $admin['cookies']);
            $html = (string) $response->getBody();
            self::assertSame(2, substr_count($html, $form), $path);
            self::assertStringContainsString('<input type="hidden" name="_csrf" value="' . $admin['csrf'] . '">', $html, $path);
            self::assertStringContainsString('<input type="hidden" name="kind" value="article">', $html, $path);
            self::assertStringContainsString('<input type="hidden" name="kind" value="page">', $html, $path);
            self::assertStringContainsString('<button class="nav-link" type="submit">New article</button>', $html, $path);
            self::assertStringContainsString('<button class="nav-link" type="submit">New page</button>', $html, $path);
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'), $path);
        }
        self::assertStringNotContainsString('<a href="/admin/articles', $this->home($admin), 'never a link that creates rows');
    }

    public function testTheFormsStartADraftOfTheirKind(): void
    {
        $admin = $this->admin();

        foreach (['page', 'article'] as $kind) {
            $response = $this->post('/admin/articles', ['kind' => $kind, '_csrf' => $admin['csrf']], $admin['cookies']);
            self::assertSame(303, $response->getStatusCode());
            self::assertMatchesRegularExpression('~^/admin/articles/[0-9]+$~', $response->getHeaderLine('Location'));
        }
        self::assertSame([['kind' => 'page', 'status' => 'draft'], ['kind' => 'article', 'status' => 'draft']], $this->rows());

        self::assertSame(403, $this->post('/admin/articles', ['kind' => 'page'], $admin['cookies'])->getStatusCode(), 'without the token');
        $reader = $this->reader();
        self::assertSame(403, $this->post('/admin/articles', ['kind' => 'page', '_csrf' => $reader['csrf']], $reader['cookies'])->getStatusCode());
        self::assertSame(303, $this->post('/admin/articles', ['kind' => 'page'])->getStatusCode(), 'signed out: to the sign-in');
        self::assertCount(2, $this->rows());
    }

    public function testAnEmptySiteOffersTheAdminTheFirstArticle(): void
    {
        $button = '<button class="button-primary" type="submit">Write the first article</button>';
        $reader = $this->reader();
        foreach ([null, $reader] as $who) {
            $html = $this->home($who);
            self::assertStringContainsString('Nothing to read yet', $html);
            self::assertStringNotContainsString($button, $html);
        }

        $admin = $this->admin();
        $html = $this->home($admin);
        self::assertStringContainsString('<form class="first-article" method="post" action="/admin/articles">', $html);
        self::assertStringContainsString($button, $html);
        self::assertSame(3, substr_count($html, '<input type="hidden" name="_csrf" value="' . $admin['csrf'] . '">'), 'the menu’s two forms and this one');

        $this->publishedArticle('en');
        self::assertStringNotContainsString($button, $this->home($admin));
        self::assertStringNotContainsString($button, $this->home($admin, '/?lang=de'), 'only when nothing is published at all');
    }

    public function testTheEditorsMediaPanelLinksToTheLibrary(): void
    {
        $admin = $this->admin();
        $response = $this->post('/admin/articles', ['kind' => 'article', '_csrf' => $admin['csrf']], $admin['cookies']);

        $editor = (string) $this->get($response->getHeaderLine('Location'), $admin['cookies'])->getBody();
        self::assertMatchesRegularExpression('~<aside id="media-panel"[^>]*>.*<p class="media-manage"><a href="/admin/media-library">Manage media</a></p>\s*</aside>~s', $editor);
        self::assertSame(200, $this->get('/admin/media-library', $admin['cookies'])->getStatusCode());
    }
}
