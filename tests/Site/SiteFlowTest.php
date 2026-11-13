<?php

declare(strict_types=1);

namespace Edict\Tests\Site;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Site\Themes;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class SiteFlowTest extends DbTestCase
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
     * @param array<string, string> $fields
     */
    private function saveSite(array $fields): ResponseInterface
    {
        return $this->post('/admin/site', $fields + ['title' => 'My Realm', '_csrf' => $this->csrf], $this->admin);
    }

    private function page(string $slug, string $title): void
    {
        $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('page', ?, 'de', ?, ?, 'published', to_timestamp(1), now(), now())",
        )->execute([$slug, $title, Body::EMPTY]);
    }

    public function testEveryPublicPageHasTheFrameWithTheDefaultTheme(): void
    {
        $home = (string) $this->get('/')->getBody();

        self::assertStringContainsString('<body class="theme-' . Themes::DEFAULT . '">', $home);
        self::assertStringContainsString('/assets/build/themes/' . Themes::DEFAULT . '.css?v=', $home);
        self::assertStringContainsString('<a class="skip-link" href="#content">', $home);
        self::assertStringContainsString('<p class="site-title"><a href="/">Edict</a></p>', $home);
        self::assertStringContainsString('<nav class="site-nav" aria-label="Main">', $home);
        self::assertStringContainsString('<footer class="site-footer">', $home);
    }

    public function testTheNameMenuAndFooterAreTheSovereigns(): void
    {
        $this->page('impressum', 'Impressum');
        $this->page('datenschutz', 'Datenschutzerklärung');

        $response = $this->saveSite([
            'title' => 'My <Realm>',
            'description' => 'Decrees & musings',
            'menu' => "About | /about\r\nCode | https://github.com/example",
            'footer' => 'Contact | mailto:me@example.org',
            'imprint' => 'impressum',
            'privacy' => 'datenschutz',
        ]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());

        foreach (['/', '/archive'] as $path) {
            $html = self::tight((string) $this->get($path)->getBody());
            self::assertStringContainsString('<a href="/">My &lt;Realm&gt;</a>', $html, $path);
            self::assertStringContainsString('<p class="site-description">Decrees &amp; musings</p>', $html);
            self::assertStringContainsString('<li><a href="/about">About</a></li><li><a href="https://github.com/example">Code</a></li><li><a href="/archive">Archive</a></li>', $html);
            self::assertStringContainsString('<li><a href="mailto:me@example.org">Contact</a></li><li><a href="/impressum">Impressum</a></li><li><a href="/datenschutz">Datenschutz</a></li>', $html);
        }

        $form = (string) $this->get('/admin/site', $this->admin)->getBody();
        self::assertStringContainsString("About | /about\nCode | https://github.com/example</textarea>", $form);
        self::assertStringContainsString('<option value="impressum" selected>', $form);
    }

    /**
     * The markup without the whitespace between tags, which templates are free to change.
     */
    private static function tight(string $html): string
    {
        return (string) preg_replace('~>\s+<~', '><', $html);
    }

    public function testDangerousLinksAreRefusedAndNothingIsSaved(): void
    {
        foreach (['Evil | javascript:alert(1)', 'Evil | //elsewhere.example', 'Missing an address', 'Evil | data:text/html,x'] as $line) {
            $response = $this->saveSite(['menu' => $line]);
            self::assertSame(422, $response->getStatusCode(), $line);
            self::assertStringContainsString('Nothing was saved', (string) $response->getBody());
        }
        self::assertSame(422, $this->saveSite(['title' => ''])->getStatusCode());
        self::assertSame(422, $this->saveSite(['imprint' => '../etc'])->getStatusCode());
        self::assertSame(422, $this->saveSite(['menu' => str_repeat("A | /a\n", 13)])->getStatusCode());

        self::assertStringNotContainsString('javascript:', (string) $this->get('/')->getBody());
        self::assertStringContainsString('>Edict</a>', (string) $this->get('/')->getBody());
    }

    public function testTheThemeIsAppliedForEveryone(): void
    {
        self::assertSame(303, $this->post('/admin/themes', ['theme' => Themes::DEFAULT, '_csrf' => $this->csrf], $this->admin)->getStatusCode());
        self::assertSame(422, $this->post('/admin/themes', ['theme' => 'nonesuch', '_csrf' => $this->csrf], $this->admin)->getStatusCode());

        $picker = (string) $this->get('/admin/themes', $this->admin)->getBody();
        foreach (Themes::ALL as $id => $theme) {
            self::assertStringContainsString('<iframe src="/?theme=' . $id . '"', $picker);
            self::assertStringContainsString($theme['name'], $picker);
        }
    }

    public function testOnlyTheSovereignCanPreviewATheme(): void
    {
        $other = array_key_last(Themes::ALL);
        $this->pdo->exec("INSERT INTO setting (key, value) VALUES ('theme', '\"nonesuch\"')");

        self::assertStringContainsString('theme-' . Themes::DEFAULT . '"', (string) $this->get('/?theme=' . $other)->getBody(), 'an unknown stored theme falls back');
        self::assertStringContainsString('theme-' . $other . '"', (string) $this->get('/?theme=' . $other, $this->admin)->getBody());
        self::assertStringContainsString('theme-' . Themes::DEFAULT . '"', (string) $this->get('/?theme=../../etc', $this->admin)->getBody());
    }

    public function testReadersCannotChangeTheSite(): void
    {
        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $insert->fetchColumn(), 3600);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        self::assertSame(403, $this->post('/admin/site', ['title' => 'Mine', '_csrf' => $session->csrfToken], [self::SESSION => $token])->getStatusCode());
        self::assertSame(403, $this->post('/admin/themes', ['theme' => Themes::DEFAULT, '_csrf' => $session->csrfToken], [self::SESSION => $token])->getStatusCode());
        self::assertSame(303, $this->get('/admin/themes')->getStatusCode());
    }
}
