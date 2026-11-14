<?php

declare(strict_types=1);

namespace Edict\Tests\Site;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Media\JobRunner;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\UploadedFile;

final class LookFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    private string $dataDir;

    /** @var array<string, string> */
    private array $cookies;

    private string $csrf;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/edict-look-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir);
        parent::setUp();
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 3600);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->cookies = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
    }

    protected function tearDown(): void
    {
        MediaStore::remove($this->dataDir);
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return ['DATA_DIR' => $this->dataDir, 'MEDIA_MIN_FREE_BYTES' => '0'];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, UploadedFile> $files
     */
    private function save(array $fields, array $files = []): ResponseInterface
    {
        return $this->postFiles('/admin/themes/look', $fields + ['_csrf' => $this->csrf, 'accent_mode' => 'theme'], $files, $this->cookies);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $path = $this->dataDir . '/incoming-' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, strlen($bytes));
    }

    private function home(): string
    {
        return (string) $this->get('/')->getBody();
    }

    private function requireTools(): void
    {
        foreach (['vips', 'vipsthumbnail', 'vipsheader'] as $tool) {
            if (trim((string) shell_exec("command -v $tool 2>/dev/null")) === '') {
                self::markTestSkipped("$tool is not installed");
            }
        }
    }

    private function png(int $width, int $height): string
    {
        $path = $this->dataDir . '/made.png';
        shell_exec('vips black ' . escapeshellarg($path) . " $width $height --bands 4 2>&1");
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        unlink($path);

        return $bytes;
    }

    public function testAnOwnAccentIsLoadedAfterTheThemeAndKeptReadable(): void
    {
        self::assertStringNotContainsString('/accent.css', $this->home(), 'the theme’s own by default');

        $saved = $this->save(['accent_mode' => 'own', 'accent' => '#FFD400']);
        self::assertSame('/admin/themes?saved=1#look', $saved->getHeaderLine('Location'));

        $home = $this->home();
        self::assertMatchesRegularExpression('~/build/themes/decree\.css\?v=[^"]+">\s*<link rel="stylesheet" href="/accent\.css\?theme=decree&amp;v=[0-9a-f]{12}">~', $home);

        $css = $this->get('/accent.css?theme=decree&v=x');
        self::assertSame('text/css; charset=utf-8', $css->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $css->getHeaderLine('Cache-Control'));
        $body = (string) $css->getBody();
        self::assertMatchesRegularExpression('~^:root \{ --accent: #[0-9a-f]{6}; --accent-fg: #(111111|ffffff); --focus: #[0-9a-f]{6}; \}~', $body);
        self::assertStringContainsString('@media (prefers-color-scheme: dark) { :root { --accent: #ffd400; --accent-fg: #111111;', $body, 'bright enough on dark as it is');
        self::assertStringNotContainsString('--accent: #ffd400', strstr($body, '@media', true) ?: '', 'darkened for the light background');
        self::assertSame("/* The theme's own accent. */\n", (string) $this->get('/accent.css?theme=nonsense')->getBody());

        $page = (string) $this->get('/admin/themes', $this->cookies)->getBody();
        self::assertStringContainsString('value="#ffd400"', $page);
        self::assertStringContainsString('Edict made it lighter or darker where needed', $page);

        $this->save(['accent_mode' => 'theme']);
        self::assertStringNotContainsString('/accent.css', $this->home());
    }

    public function testABadColourIsRefused(): void
    {
        $response = $this->save(['accent_mode' => 'own', 'accent' => 'red; }']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please choose a colour', (string) $response->getBody());
        self::assertStringNotContainsString('/accent.css', $this->home());
    }

    public function testOnlyTheAdminChangesTheLook(): void
    {
        self::assertSame(403, $this->postFiles('/admin/themes/look', ['accent_mode' => 'own', 'accent' => '#000'], [], $this->cookies)->getStatusCode());
        self::assertSame(303, $this->postFiles('/admin/themes/look', ['accent_mode' => 'own', 'accent' => '#000'], [], [])->getStatusCode());
        self::assertStringNotContainsString('/accent.css', $this->home());
    }

    public function testALogoMustBeAnImage(): void
    {
        $response = $this->save([], ['logo' => $this->file('notes.txt', "just text\n")]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please choose an image', (string) $response->getBody());
        self::assertStringNotContainsString('site-logo', $this->home());
    }

    public function testLogoAndIconAppearOnceProcessed(): void
    {
        $this->requireTools();
        $saved = $this->save(['logo_only' => '1'], [
            'logo' => $this->file('logo.png', $this->png(600, 200)),
            'favicon' => $this->file('icon.png', $this->png(300, 200)),
        ]);
        self::assertSame(303, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertStringContainsString('being prepared', (string) $this->get('/admin/themes', $this->cookies)->getBody());
        self::assertStringContainsString('href="/favicon.svg"', $this->home(), 'Edict’s seal until the icons exist');

        while ($this->service(JobRunner::class)->runNext()) {
            // Images, then the icons.
        }

        $home = $this->home();
        self::assertMatchesRegularExpression('~<img class="site-logo" src="/media/[^"]+-w480-webp\.webp"[^>]* alt="Edict">~', $home);
        self::assertStringNotContainsString('<span class="site-name">', $home, 'only the logo; its alt text names the site');
        self::assertSame(1, preg_match('~<link rel="icon" href="(/media/[^"]+-icon-32\.png)" sizes="32x32"~', $home, $icon), $home);
        $iconFile = $this->dataDir . ($icon[1] ?? '');
        self::assertFileExists($iconFile);
        $size = getimagesize($iconFile);
        self::assertIsArray($size);
        self::assertSame([32, 32], [$size[0], $size[1]], 'cut to a square');
        self::assertStringContainsString('apple-touch-icon" href="/media/', $home);

        $this->save(['logo_remove' => '1', 'favicon_remove' => '1']);
        $home = $this->home();
        self::assertStringNotContainsString('site-logo', $home);
        self::assertStringContainsString('<a href="/">Edict</a>', $home);
        self::assertStringContainsString('href="/favicon.svg"', $home);
    }
}
