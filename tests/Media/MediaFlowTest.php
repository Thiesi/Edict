<?php

declare(strict_types=1);

namespace Edict\Tests\Media;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Media\FileType;
use Edict\Media\JobQueue;
use Edict\Media\JobRunner;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;

final class MediaFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';
    private const PNG = "\x89PNG\r\n\x1A\n";

    private string $dataDir;

    /** @var array<string, string> */
    private array $settings = [];

    /** @var array<string, string> */
    private array $admin;

    /** @var array<string, string> */
    private array $csrf;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/edict-media-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir);
        parent::setUp();
        $this->signIn();
    }

    protected function tearDown(): void
    {
        MediaStore::remove($this->dataDir);
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return $this->settings + ['DATA_DIR' => $this->dataDir, 'MEDIA_MIN_FREE_BYTES' => '0'];
    }

    private function signIn(): void
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = ['X-CSRF-Token' => $session->csrfToken];
    }

    /**
     * @param array<string, string> $settings
     */
    private function reboot(array $settings): void
    {
        $this->settings = $settings;
        $this->pdo->exec('TRUNCATE app_user CASCADE');
        parent::setUp();
        $this->signIn();
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, (string) $response->getBody());

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $media
     */
    private static function id(array $media): int
    {
        self::assertIsInt($media['id']);

        return $media['id'];
    }

    private function begin(string $name, int $size): ResponseInterface
    {
        return $this->post('/admin/uploads', ['name' => $name, 'size' => $size], $this->admin, $this->csrf);
    }

    private function started(string $name, int $size): string
    {
        $response = $this->begin($name, $size);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $id = self::data($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function chunk(string $id, int $offset, string $bytes): ResponseInterface
    {
        return $this->raw('PUT', "/admin/uploads/$id?offset=$offset", (new StreamFactory())->createStream($bytes), $this->admin, $this->csrf);
    }

    /**
     * @return array<string, mixed> the media
     */
    private function upload(string $name, string $bytes): array
    {
        $id = $this->started($name, strlen($bytes));
        self::assertSame(200, $this->chunk($id, 0, $bytes)->getStatusCode());
        $done = $this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getBody());

        return self::data($done);
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string> every file under the data directory, relative to it
     */
    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->dataDir) + 1));
            }
        }
        sort($files);

        return $files;
    }

    public function testChunksBecomeOneStoredFile(): void
    {
        $bytes = str_repeat('a', 1000) . str_repeat('b', 500);
        $id = $this->started('notes.txt', 1500);

        self::assertSame(['received' => 1000], self::data($this->chunk($id, 0, substr($bytes, 0, 1000))));
        self::assertSame(['received' => 1000], self::data($this->get("/admin/uploads/$id", $this->admin)));
        self::assertSame(['received' => 1500], self::data($this->chunk($id, 1000, substr($bytes, 1000))));

        $done = self::data($this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf));

        self::assertSame('file', $done['kind']);
        self::assertSame('notes.txt', $done['name']);
        self::assertSame(1500, $done['bytes']);
        self::assertSame('ready', $done['status']);

        $sha = hash('sha256', $bytes);
        self::assertSame([sprintf('private/%s/%s/%s', substr($sha, 0, 2), substr($sha, 2, 2), $sha)], $this->files());
        self::assertSame($bytes, file_get_contents($this->dataDir . '/' . $this->files()[0]));
        self::assertSame(0, $this->rows('upload'));
    }

    public function testARepeatedOrMisplacedChunkChangesNothing(): void
    {
        $id = $this->started('a.txt', 20);
        $this->chunk($id, 0, str_repeat('a', 10));

        self::assertSame(['received' => 10], self::data($this->chunk($id, 0, str_repeat('x', 10))));
        self::assertSame(['received' => 10], self::data($this->chunk($id, 15, str_repeat('x', 5))));

        self::assertSame(['received' => 20], self::data($this->chunk($id, 10, str_repeat('b', 10))));
        $done = self::data($this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf));
        self::assertSame(20, $done['bytes']);
        self::assertNotNull($this->service(MediaLibrary::class)->findBySha(hash('sha256', str_repeat('a', 10) . str_repeat('b', 10))));
    }

    public function testAChunkBeyondTheDeclaredSizeIsRefused(): void
    {
        $id = $this->started('a.txt', 10);

        self::assertSame(422, $this->chunk($id, 0, str_repeat('a', 11))->getStatusCode());
        self::assertSame(['received' => 0], self::data($this->get("/admin/uploads/$id", $this->admin)));

        self::assertSame(['received' => 10], self::data($this->chunk($id, 0, str_repeat('a', 10))));
    }

    public function testAnUnfinishedUploadCannotComplete(): void
    {
        $id = $this->started('a.txt', 10);
        $this->chunk($id, 0, 'abc');

        self::assertSame(422, $this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf)->getStatusCode());
        self::assertSame(0, $this->rows('media'));
    }

    public function testUnknownUploadIsRefused(): void
    {
        self::assertSame(422, $this->get('/admin/uploads/nothing', $this->admin)->getStatusCode());
        self::assertSame(422, $this->chunk('../../etc', 0, 'x')->getStatusCode());
    }

    public function testSameContentIsStoredOnce(): void
    {
        $first = $this->upload('first.txt', 'same bytes');
        $second = $this->upload('second.txt', 'same bytes');

        self::assertSame($first['id'], $second['id']);
        self::assertSame('first.txt', $second['name']);
        self::assertCount(1, $this->files());
        self::assertSame(1, $this->rows('media'));
    }

    public function testNamesNeverBecomePaths(): void
    {
        $media = $this->upload('../../../evil/<b>x.php', 'content');

        self::assertSame('......evil<b>x.php', $media['name']);
        foreach ($this->files() as $file) {
            self::assertStringNotContainsString('evil', $file);
            self::assertStringNotContainsString('.php', $file);
        }
    }

    public function testTheContentDecidesTheType(): void
    {
        $image = $this->upload('looks-like-text.txt', self::PNG . 'rest');
        $text = $this->upload('looks-like-image.png', 'just text');

        self::assertSame('image', $image['kind']);
        self::assertSame('processing', $image['status']);
        self::assertSame('file', $text['kind']);
        self::assertSame(1, $this->rows('job'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function signatures(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0", 'image', 'jpg'];
        yield 'png' => [self::PNG, 'image', 'png'];
        yield 'gif' => ['GIF89a', 'image', 'gif'];
        yield 'webp' => ['RIFF0000WEBP', 'image', 'webp'];
        yield 'avif' => ["\0\0\0\x20ftypavif", 'image', 'avif'];
        yield 'heic' => ["\0\0\0\x20ftypheic", 'image', 'heic'];
        yield 'mp4 is not an image' => ["\0\0\0\x20ftypisom", 'file', 'bin'];
        yield 'svg is not an image' => ['<svg xmlns="x"><script>x</script></svg>', 'file', 'bin'];
        yield 'html' => ['<html>', 'file', 'bin'];
        yield 'empty' => ['', 'file', 'bin'];
    }

    #[DataProvider('signatures')]
    public function testFileTypes(string $head, string $kind, string $extension): void
    {
        $path = $this->dataDir . '/sample';
        file_put_contents($path, $head);

        self::assertSame(['kind' => $kind, 'extension' => $extension], FileType::of($path));
    }

    public function testRefusedSizes(): void
    {
        self::assertSame(422, $this->begin('a', 0)->getStatusCode());
        self::assertSame(422, $this->begin('a', 5_000_000_000)->getStatusCode());
        self::assertSame(400, $this->post('/admin/uploads', ['name' => 'a', 'size' => '5'], $this->admin, $this->csrf)->getStatusCode());
    }

    public function testStorageCapFailsBeforeWriting(): void
    {
        $this->reboot(['MEDIA_MAX_BYTES' => '100']);
        $this->upload('a.txt', str_repeat('a', 60));

        $response = $this->begin('b.txt', 60);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('storage limit', (string) $response->getBody());
        self::assertSame(0, $this->rows('upload'));
    }

    public function testFileCapFailsBeforeWriting(): void
    {
        $this->reboot(['MEDIA_MAX_FILES' => '1']);
        $this->upload('a.txt', 'one');

        $response = $this->begin('b.txt', 3);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('file limit', (string) $response->getBody());
    }

    public function testTooLittleFreeDiskFailsBeforeWriting(): void
    {
        $this->reboot(['MEDIA_MIN_FREE_BYTES' => (string) PHP_INT_MAX]);

        $response = $this->begin('a.txt', 3);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('free disk', (string) $response->getBody());
    }

    public function testWithoutADataDirectoryUploadsSaySo(): void
    {
        $this->reboot(['DATA_DIR' => '']);

        $response = $this->begin('a.txt', 3);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('DATA_DIR', (string) $response->getBody());
    }

    public function testRoutesNeedAdminAndCsrf(): void
    {
        $id = $this->started('a.txt', 3);

        self::assertSame(303, $this->post('/admin/uploads', ['name' => 'a', 'size' => 1])->getStatusCode());
        self::assertSame(303, $this->get('/admin/media')->getStatusCode());
        self::assertSame(403, $this->post('/admin/uploads', ['name' => 'a', 'size' => 1], $this->admin)->getStatusCode());
        self::assertSame(403, $this->raw('PUT', "/admin/uploads/$id?offset=0", (new StreamFactory())->createStream('abc'), $this->admin, [])->getStatusCode());
    }

    public function testLibraryListsNewestFirst(): void
    {
        $this->upload('first.txt', 'one');
        $second = $this->upload('second.txt', 'two');

        $list = self::data($this->get('/admin/media', $this->admin))['media'] ?? null;

        self::assertIsArray($list);
        self::assertCount(2, $list);
        self::assertIsArray($list[0]);
        self::assertSame($second['id'], $list[0]['id']);
        self::assertIsInt($list[0]['id']);
        self::assertSame(200, $this->get('/admin/media/' . $list[0]['id'], $this->admin)->getStatusCode());
        self::assertSame(404, $this->get('/admin/media/999', $this->admin)->getStatusCode());
    }

    public function testSweepRemovesOnlyOldTempFiles(): void
    {
        $store = $this->service(MediaStore::class);
        $old = $store->uploadPath('old');
        $new = $store->uploadPath('new');
        touch($old, time() - 3600);
        touch($new);
        $job = $store->newJobDir();
        touch($job . '/leftover');
        touch($job, time() - 3600);

        $store->sweep(time() - 60);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($new);
        self::assertDirectoryDoesNotExist($job);
    }

    public function testJobsRetryThenFailAndMarkTheMedia(): void
    {
        $this->reboot(['VIPSHEADER_BIN' => 'no-such-tool-anywhere']);
        $media = $this->upload('a.png', self::PNG . 'rest');
        $runner = $this->service(JobRunner::class);

        self::assertTrue($runner->runNext());
        self::assertFalse($runner->runNext(), 'waits before retrying');
        $status = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('processing', $status['status'] ?? null);

        for ($i = 0; $i < 2; ++$i) {
            $this->clock->advance(61);
            self::assertTrue($runner->runNext());
        }

        $status = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('failed', $status['status'] ?? null);
        self::assertIsString($status['error'] ?? null);
        self::assertFalse($runner->runNext());
        self::assertDirectoryDoesNotExist($this->dataDir . '/media/' . substr(hash('sha256', self::PNG . 'rest'), 0, 2));
        self::assertSame([], glob($this->dataDir . '/job-tmp/*'), 'the job dir is removed even on failure');
    }

    public function testADeadWorkersJobIsPickedUpAgain(): void
    {
        $queue = $this->service(JobQueue::class);
        $queue->enqueue('image', ['media_id' => 1]);
        self::assertNotNull($queue->claim());
        self::assertNull($queue->claim());

        $queue->housekeeping();
        self::assertNull($queue->claim(), 'still within its time');

        $this->clock->advance(3601);
        $queue->housekeeping();
        self::assertNotNull($queue->claim());
    }

    public function testDevelopmentRouteServesOnlyVariantNames(): void
    {
        foreach (['/media/x', '/media/../../etc/passwd', '/media/ab/cd/' . str_repeat('a', 64) . '-w1.php'] as $path) {
            self::assertSame(404, $this->get($path)->getStatusCode(), $path);
        }
    }

    /**
     * The real thing, where the tools are installed: CI has them.
     */
    private function requireTools(): void
    {
        foreach (['vips', 'vipsthumbnail', 'vipsheader', 'exiftool'] as $tool) {
            if (trim((string) shell_exec("command -v $tool 2>/dev/null")) === '') {
                self::markTestSkipped("$tool is not installed");
            }
        }
    }

    /**
     * A 3000x2000 JPEG, upright only after rotating, with a GPS position in it.
     */
    private function jpegWithGps(): string
    {
        $path = $this->dataDir . '/source.jpg';
        shell_exec('vips black ' . escapeshellarg($path) . ' 3000 2000 2>&1');
        shell_exec('exiftool -overwrite_original -GPSLatitude=52.5 -GPSLongitude=13.4 -Orientation#=6 ' . escapeshellarg($path) . ' 2>&1');
        // Proves the checks below would notice.
        self::assertNotSame('', trim((string) shell_exec('exiftool -s -s -s -gps:all ' . escapeshellarg($path) . ' 2>&1')));
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        unlink($path);

        return $bytes;
    }

    public function testImagesBecomeCleanUprightVariants(): void
    {
        $this->requireTools();
        $bytes = $this->jpegWithGps();

        $media = $this->upload('photo.jpg', $bytes);
        self::assertTrue($this->service(JobRunner::class)->runNext());

        $done = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('ready', $done['status'] ?? null, (string) json_encode($done));
        self::assertSame(2000, $done['width'] ?? null, 'rotated upright');
        self::assertSame(3000, $done['height'] ?? null);

        $variants = $done['variants'] ?? null;
        self::assertIsArray($variants);
        $presets = array_column($variants, 'preset');
        foreach (['w480-avif', 'w480-webp', 'w480-jpg', 'w960-avif', 'w1600-webp', 'full'] as $preset) {
            self::assertContains($preset, $presets);
        }
        self::assertNotContains('w2400-jpg', $presets, 'never enlarged');

        foreach ($variants as $variant) {
            self::assertIsArray($variant);
            self::assertIsString($variant['url']);
            $path = $this->dataDir . $variant['url'];
            self::assertFileExists($path);
            self::assertSame('0644', substr(sprintf('%o', fileperms($path)), -4), $variant['url']);
            self::assertSame('', trim((string) shell_exec('exiftool -s -s -s -gps:all ' . escapeshellarg($path) . ' 2>&1')), 'GPS in ' . $variant['url']);
            self::assertSame('', trim((string) shell_exec('exiftool -s -s -s -Orientation ' . escapeshellarg($path) . ' 2>&1')), 'orientation in ' . $variant['url']);
        }

        $original = $this->service(MediaStore::class)->originalPath(hash('sha256', $bytes));
        self::assertSame('0600', substr(sprintf('%o', fileperms($original)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->dataDir . '/private')), -4));
        self::assertIsArray($variants[0]);
        self::assertIsString($variants[0]['url']);
        self::assertSame('0755', substr(sprintf('%o', fileperms(dirname($this->dataDir . $variants[0]['url']))), -4));
        self::assertSame([], glob($this->dataDir . '/job-tmp/*'));
    }

    public function testASmallImageIsNotEnlarged(): void
    {
        $this->requireTools();
        $path = $this->dataDir . '/small.png';
        shell_exec('vips black ' . escapeshellarg($path) . ' 100 50 2>&1');
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        unlink($path);

        $media = $this->upload('small.png', $bytes);
        $this->service(JobRunner::class)->runNext();

        $done = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('ready', $done['status'] ?? null, (string) json_encode($done));
        self::assertIsArray($done['variants'] ?? null);
        foreach ($done['variants'] as $variant) {
            self::assertIsArray($variant);
            self::assertSame(100, $variant['width']);
        }
    }

    public function testACorruptImageFailsAndPublishesNothing(): void
    {
        $this->requireTools();
        $media = $this->upload('broken.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('x', 500));
        $runner = $this->service(JobRunner::class);

        $runner->runNext();
        for ($i = 0; $i < 2; ++$i) {
            $this->clock->advance(61);
            $runner->runNext();
        }

        self::assertSame('failed', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);
        self::assertSame([], array_values(array_filter($this->files(), static fn(string $file): bool => str_starts_with($file, 'media/'))));
    }

    public function testGeneratedFilesAreServedInDevelopment(): void
    {
        $this->requireTools();
        $media = $this->upload('photo.jpg', $this->jpegWithGps());
        $this->service(JobRunner::class)->runNext();

        $done = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertIsArray($done['variants'] ?? null);
        self::assertIsArray($done['variants'][0]);
        self::assertIsString($done['variants'][0]['url']);

        $response = $this->get($done['variants'][0]['url']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('image/', $response->getHeaderLine('Content-Type'));
    }
}
