<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Http\Middleware\SecurityHeadersMiddleware;
use Edict\Tests\DbTestCase;

final class HardeningFlowTest extends DbTestCase
{
    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function admin(): array
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 3600);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        return ['cookies' => ['edict_session' => $token], 'csrf' => $session->csrfToken];
    }

    public function testEveryPageCarriesTheSecurityHeaders(): void
    {
        foreach (['/', '/login', '/no-such-page', '/feed.atom'] as $path) {
            $response = $this->get($path);
            self::assertSame(SecurityHeadersMiddleware::POLICY, $response->getHeaderLine('Content-Security-Policy'), $path);
            self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
            self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
            self::assertStringContainsString('camera=()', $response->getHeaderLine('Permissions-Policy'));
        }
        self::assertStringNotContainsString("'unsafe-inline'", SecurityHeadersMiddleware::POLICY);
        self::assertStringContainsString("script-src 'self';", SecurityHeadersMiddleware::POLICY);
    }

    public function testTheLegalPagesStartFromGermanTemplates(): void
    {
        $admin = $this->admin();

        $response = $this->post('/admin/site/legal/privacy', ['_csrf' => $admin['csrf']], $admin['cookies']);

        self::assertSame(303, $response->getStatusCode());
        $id = (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        $article = $this->service(Articles::class)->find($id);
        self::assertNotNull($article);
        self::assertSame('page', $article->kind);
        self::assertSame('draft', $article->status, 'nothing goes live without being checked');
        $input = $article->input();
        self::assertSame('Datenschutzerklärung', $input->title);
        self::assertSame('datenschutz', $input->slug);
        self::assertSame('de', $input->lang);
        self::assertStringContainsString('Anmeldung mit Google oder Twitch', $input->body);
        self::assertStringContainsString('[eckigen Klammern]', $input->body);
        self::assertSame(404, $this->post('/admin/site/legal/other', ['_csrf' => $admin['csrf']], $admin['cookies'])->getStatusCode());
    }

    public function testTheDashboardShowsHowFullStorageIs(): void
    {
        $admin = $this->admin();

        $html = (string) $this->get('/admin', $admin['cookies'])->getBody();

        self::assertStringContainsString('<h2>Storage</h2>', $html);
        self::assertMatchesRegularExpression('~Media: 0\.0 of [0-9.]+ GB,\s+0 of [0-9,]+ files\.~', $html);
    }
}
