<?php

declare(strict_types=1);

namespace Edict\Tests\Media;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Cli\WorkerCommand;
use Edict\Media\FileType;
use Edict\Media\JobQueue;
use Edict\Media\JobRunner;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Edict\Services;
use Edict\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testAChunkOverTheChunkSizeIsRefusedWhateverTheUploadSize(): void
    {
        $id = $this->started('big.bin', 3 * MediaLibrary::CHUNK_BYTES);

        // Apache doesn't limit bodies on PHP routes, so this limit has to hold by itself.
        $response = $this->chunk($id, 0, str_repeat('x', MediaLibrary::CHUNK_BYTES + 1));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['received' => 0], self::data($this->get("/admin/uploads/$id", $this->admin)));
        self::assertSame(0, (int) filesize($this->service(MediaStore::class)->uploadPath($id)));

        self::assertSame(['received' => MediaLibrary::CHUNK_BYTES], self::data($this->chunk($id, 0, str_repeat('x', MediaLibrary::CHUNK_BYTES))));
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
        self::assertSame(422, $this->chunk('etc', 0, 'x')->getStatusCode());
        self::assertSame(404, $this->chunk('../../etc', 0, 'x')->getStatusCode(), 'a path never reaches the code');
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
        yield 'mp4' => ["\0\0\0\x20ftypisom", 'video', 'mp4'];
        yield 'phone video' => ["\0\0\0\x20ftypmp42", 'video', 'mp4'];
        yield 'quicktime' => ["\0\0\0\x14ftypqt  ", 'video', 'mov'];
        yield 'old quicktime' => ["\0\0\0\x20moov", 'video', 'mov'];
        yield 'matroska and webm' => ["\x1A\x45\xDF\xA3", 'video', 'mkv'];
        yield 'avi' => ['RIFF0000AVI LIST', 'video', 'avi'];
        yield 'm4a' => ["\0\0\0\x20ftypM4A ", 'audio', 'm4a'];
        yield 'wav' => ['RIFF0000WAVEfmt ', 'audio', 'wav'];
        yield 'flac' => ['fLaC', 'audio', 'flac'];
        yield 'ogg' => ['OggS', 'audio', 'ogg'];
        yield 'mp3 with tags' => ['ID3', 'audio', 'mp3'];
        yield 'mp3 without' => ["\xFF\xFB\x90\x00", 'audio', 'mp3'];
        yield 'raw aac' => ["\xFF\xF1\x50\x80", 'audio', 'aac'];
        yield 'unknown iso brand' => ["\0\0\0\x20ftypzzzz", 'file', 'bin'];
        yield 'not quite mp3' => ["\xFF\xFF\xFF\xFF", 'file', 'bin'];
        yield 'utf-16 text is not mp3' => ["\xFF\xFEH\x00e\x00", 'file', 'bin'];
        yield 'a playlist ffmpeg would follow' => ["#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n", 'file', 'bin'];
        yield 'a concat list ffmpeg would follow' => ["ffconcat version 1.0\nfile /etc/passwd\n", 'file', 'bin'];
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
        self::assertSame(1, $this->rows('upload'), 'only the finished one');
    }

    public function testUnfinishedUploadsReserveTheirSize(): void
    {
        $this->reboot(['MEDIA_MAX_BYTES' => '100']);
        $first = $this->started('a.txt', 60);

        self::assertSame(422, $this->begin('b.txt', 60)->getStatusCode(), 'the first has reserved 60');

        self::assertSame(200, $this->raw('DELETE', "/admin/uploads/$first", (new StreamFactory())->createStream(''), $this->admin, $this->csrf)->getStatusCode());
        self::assertFileDoesNotExist($this->service(MediaStore::class)->uploadPath($first));

        self::assertSame(200, $this->begin('b.txt', 60)->getStatusCode(), 'aborting frees it');
    }

    public function testTheNumberOfOpenUploadsIsLimited(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->started("$i.txt", 1);
        }

        $response = $this->begin('one-too-many.txt', 1);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('unfinished', (string) $response->getBody());
    }

    public function testCompletingCountsAgainstTheCapButNotTwice(): void
    {
        $this->reboot(['MEDIA_MAX_BYTES' => '100']);

        // Its own reservation doesn't block it.
        self::assertSame(90, $this->upload('a.txt', str_repeat('a', 90))['bytes']);
    }

    public function testCompletingAgainGivesTheSameAnswer(): void
    {
        $id = $this->started('a.txt', 3);
        $this->chunk($id, 0, 'abc');

        $first = self::data($this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf));
        $again = self::data($this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf));

        self::assertSame($first, $again);
        self::assertSame(1, $this->rows('media'));
    }

    public function testAFinishedUploadTakesNoMoreChunksAndCantBeAborted(): void
    {
        $id = $this->started('a.txt', 3);
        $this->chunk($id, 0, 'abc');
        $this->post("/admin/uploads/$id/complete", [], $this->admin, $this->csrf);

        self::assertSame(422, $this->chunk($id, 3, 'd')->getStatusCode());
        $this->raw('DELETE', "/admin/uploads/$id", (new StreamFactory())->createStream(''), $this->admin, $this->csrf);
        self::assertSame(1, $this->rows('upload'));
    }

    public function testReuploadingAFailedImageTriesAgain(): void
    {
        $this->reboot(['VIPSHEADER_BIN' => 'no-such-tool-anywhere']);
        $bytes = self::PNG . 'rest';
        $media = $this->upload('a.png', $bytes);
        $runner = $this->service(JobRunner::class);
        $runner->runNext();
        for ($i = 0; $i < 2; ++$i) {
            $this->clock->advance(61);
            $runner->runNext();
        }
        self::assertSame('failed', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);

        $again = $this->upload('a.png', $bytes);

        self::assertSame($media['id'], $again['id']);
        self::assertSame('processing', $again['status']);
        self::assertNull($again['error']);
        self::assertTrue($runner->runNext(), 'a new job was queued');
    }

    public function testADeadWorkersJobEventuallyFailsTheMedia(): void
    {
        $media = $this->upload('a.png', self::PNG . 'rest');
        $queue = $this->service(JobQueue::class);
        $runner = $this->service(JobRunner::class);

        for ($i = 0; $i < 3; ++$i) {
            self::assertNotNull($queue->claim(), "attempt $i");
            $this->clock->advance(3601);
            $runner->housekeeping();
        }

        self::assertNull($queue->claim());
        self::assertSame('failed', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);
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
        shell_exec('exiftool -overwrite_original -GPSLatitude=52.5 -GPSLongitude=13.4 -XMP:Creator=Someone -IPTC:Keywords=private -Orientation#=6 ' . escapeshellarg($path) . ' 2>&1');
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
            self::assertSame('', trim((string) shell_exec('exiftool -s -s -s -xmp:all -iptc:all ' . escapeshellarg($path) . ' 2>&1')), 'XMP or IPTC in ' . $variant['url']);
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

    public function testAFailureHalfwayPublishesNothing(): void
    {
        $this->requireTools();
        // The real tool, except that it refuses AVIF: by then other versions exist.
        $script = $this->dataDir . '/fussy-vipsthumbnail';
        file_put_contents($script, "#!/bin/sh\ncase \"$*\" in *.avif*) exit 1 ;; esac\nexec vipsthumbnail \"$@\"\n");
        chmod($script, 0o755);
        $this->reboot(['VIPSTHUMBNAIL_BIN' => $script]);

        $media = $this->upload('photo.jpg', $this->jpegWithGps());
        $this->service(JobRunner::class)->runNext();

        self::assertSame('processing', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);
        self::assertSame(0, $this->rows('media_variant'));
        self::assertSame([], array_values(array_filter($this->files(), static fn(string $file): bool => str_starts_with($file, 'media/'))));
        self::assertSame([], glob($this->dataDir . '/job-tmp/*'));
    }

    public function testTransparentImagesGetAWhiteBackgroundInJpeg(): void
    {
        $this->requireTools();
        $path = $this->dataDir . '/clear.png';
        shell_exec('vips black ' . escapeshellarg($path) . ' 600 400 --bands 4 2>&1');
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        unlink($path);

        $media = $this->upload('clear.png', $bytes);
        $this->service(JobRunner::class)->runNext();

        $done = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('ready', $done['status'] ?? null, (string) json_encode($done));
        self::assertIsArray($done['variants'] ?? null);
        foreach ($done['variants'] as $variant) {
            self::assertIsArray($variant);
            if ($variant['format'] === 'jpg') {
                self::assertIsString($variant['url']);
                $average = trim((string) shell_exec('vips avg ' . escapeshellarg($this->dataDir . $variant['url']) . ' 2>&1'));
                self::assertGreaterThan(250.0, (float) $average, 'not black');
            }
        }
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

    private function requireFfmpeg(): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            if (trim((string) shell_exec("command -v $tool 2>/dev/null")) === '') {
                self::markTestSkipped("$tool is not installed");
            }
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function ffmpegMakes(string $name, array $arguments): string
    {
        $path = $this->dataDir . '/' . $name;
        $command = 'ffmpeg -nostdin -hide_banner -loglevel error -y ' . implode(' ', array_map(escapeshellarg(...), [...$arguments, $path])) . ' 2>&1';
        $failure = shell_exec($command);
        self::assertFileExists($path, (string) $failure);
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        unlink($path);

        return $bytes;
    }

    /**
     * @param array<string, mixed> $media
     *
     * @return array<string, mixed> the variant, with where it is on disk
     */
    private function variant(array $media, string $preset): array
    {
        self::assertIsArray($media['variants']);
        foreach ($media['variants'] as $variant) {
            self::assertIsArray($variant);
            if ($variant['preset'] === $preset) {
                self::assertIsString($variant['url']);
                $variant['path'] = $this->dataDir . '/media/' . substr($variant['url'], strlen('/media/'));
                self::assertFileExists($variant['path']);

                /** @var array<string, mixed> $variant */
                return $variant;
            }
        }

        self::fail("No variant $preset");
    }

    /**
     * @param array<string, mixed> $variant
     *
     * @return array<mixed>
     */
    private function probed(array $variant): array
    {
        self::assertIsString($variant['path']);
        $json = shell_exec('ffprobe -v error -show_format -show_streams -of json ' . escapeshellarg($variant['path']) . ' 2>&1');
        self::assertIsString($json);
        $found = json_decode($json, true);
        self::assertIsArray($found, $json);

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function processedUpload(string $name, string $bytes): array
    {
        $media = $this->upload($name, $bytes);
        self::assertSame('processing', $media['status']);
        self::assertTrue($this->service(JobRunner::class)->runNext());
        $done = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertNotNull($done);
        self::assertSame([], glob($this->dataDir . '/job-tmp/*'), 'the job dir is removed');

        return $done;
    }

    /**
     * @return list<string>
     */
    private static function testVideo(string $size, string $extra = ''): array
    {
        return [
            '-f', 'lavfi', '-i', "testsrc2=size=$size:rate=10:duration=2",
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-metadata', 'title=Secret title', '-metadata', 'location=+52.5200+013.4000/',
            '-movflags', 'use_metadata_tags', '-c:v', 'mpeg4', '-c:a', 'aac', ...($extra === '' ? [] : explode(' ', $extra)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function testVideoWithoutTags(string $size): array
    {
        return ['-f', 'lavfi', '-i', "testsrc2=size=$size:rate=10:duration=1", '-c:v', 'mpeg4'];
    }

    public function testAVideoBecomesAPlayableMp4WithoutItsMetadata(): void
    {
        $this->requireFfmpeg();
        $bytes = $this->ffmpegMakes('source.mov', self::testVideo('1280x720'));
        self::assertStringContainsString('Secret title', $bytes, 'proves the check below would notice');
        self::assertStringContainsString('52.5200', $bytes, 'proves the check below would notice');

        $media = $this->processedUpload('holiday.mov', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame('video', $media['kind']);
        self::assertSame(1280, $media['width']);
        self::assertSame(720, $media['height']);
        self::assertEqualsWithDelta(2.0, $media['seconds'], 0.5);

        $video = $this->variant($media, 'h264');
        $found = $this->probed($video);
        self::assertIsArray($found['streams']);
        self::assertSame(['h264', 'aac'], array_column($found['streams'], 'codec_name'));

        self::assertIsString($video['path']);
        $published = file_get_contents($video['path']);
        self::assertIsString($published);
        self::assertStringNotContainsString('Secret title', $published);
        self::assertStringNotContainsString('52.5200', $published);
        $moov = strpos($published, 'moov');
        $mdat = strpos($published, 'mdat');
        self::assertIsInt($moov);
        self::assertIsInt($mdat);
        self::assertLessThan($mdat, $moov, 'faststart: a browser can start before the whole file has arrived');

        $poster = $this->variant($media, 'poster');
        self::assertSame('jpg', $poster['format']);
        self::assertSame(1280, $poster['width']);
        self::assertIsString($poster['path']);
        self::assertSame("\xFF\xD8\xFF", substr((string) file_get_contents($poster['path']), 0, 3));

        $sha256 = hash('sha256', $bytes);
        self::assertSame($bytes, file_get_contents($this->service(MediaStore::class)->originalPath($sha256)), 'the original stays, untouched');
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function videoSizes(): iterable
    {
        yield 'wider than 1080p' => ['2560x1440', 1920, 1080];
        yield 'tall phone video' => ['1440x2560', 1080, 1920];
        yield 'small stays small' => ['320x240', 320, 240];
        yield 'odd sizes become even' => ['321x241', 320, 240];
    }

    #[DataProvider('videoSizes')]
    public function testVideoSizes(string $size, int $width, int $height): void
    {
        $this->requireFfmpeg();

        $media = $this->processedUpload('v.mp4', $this->ffmpegMakes('source.mp4', self::testVideo($size)));

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame([$width, $height], [$media['width'], $media['height']]);
    }

    public function testAVideoFilmedSidewaysComesOutUpright(): void
    {
        $this->requireFfmpeg();
        $source = $this->ffmpegMakes('flat.mp4', self::testVideo('640x360'));
        $path = $this->dataDir . '/flat.mp4';
        file_put_contents($path, $source);

        $rotated = $this->ffmpegMakes('turned.mp4', ['-display_rotation', '90', '-i', $path, '-c', 'copy']);
        unlink($path);

        $media = $this->processedUpload('turned.mp4', $rotated);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame([360, 640], [$media['width'], $media['height']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hdrTransfers(): iterable
    {
        yield 'HLG, from iPhones' => ['arib-std-b67'];
        yield 'PQ, or HDR10' => ['smpte2084'];
    }

    #[DataProvider('hdrTransfers')]
    public function testHdrVideoBecomesOrdinaryVideo(string $transfer): void
    {
        $this->requireFfmpeg();
        $this->requireTools();
        $bytes = $this->ffmpegMakes('hdr.mp4', [
            '-f', 'lavfi', '-i', 'color=c=0x808080:size=320x240:rate=10:duration=1',
            '-c:v', 'libx265', '-preset', 'ultrafast', '-x265-params', 'log-level=error', '-pix_fmt', 'yuv420p10le',
            '-color_primaries', 'bt2020', '-color_trc', $transfer, '-colorspace', 'bt2020nc',
        ]);

        $media = $this->processedUpload('hdr.mp4', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        $found = $this->probed($this->variant($media, 'h264'));
        self::assertIsArray($found['streams']);
        self::assertIsArray($found['streams'][0]);
        self::assertSame('h264', $found['streams'][0]['codec_name']);
        self::assertSame('yuv420p', $found['streams'][0]['pix_fmt']);
        self::assertSame('bt709', $found['streams'][0]['color_transfer']);
        self::assertSame('bt709', $found['streams'][0]['color_primaries']);

        // A grey signal stays about the same grey: the conversion didn't crush or wash it out.
        $poster = $this->variant($media, 'poster');
        self::assertIsString($poster['path']);
        self::assertEqualsWithDelta(128.0, (float) shell_exec('vips avg ' . escapeshellarg($poster['path']) . ' 2>&1'), 25.0);
    }

    public function testAVideoWithoutSoundIsFine(): void
    {
        $this->requireFfmpeg();

        $media = $this->processedUpload('silent.mp4', $this->ffmpegMakes('silent.mp4', ['-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=10:duration=1', '-c:v', 'mpeg4']));

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        $found = $this->probed($this->variant($media, 'h264'));
        self::assertIsArray($found['streams']);
        self::assertSame(['h264'], array_column($found['streams'], 'codec_name'));
    }

    public function testRecordingsBecomeAacWithPeaks(): void
    {
        $this->requireFfmpeg();
        // Long enough for the peaks to be read in several pieces: silent, then a tone.
        $bytes = $this->ffmpegMakes('song.flac', [
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=300', '-af', "volume='if(lt(t,150),0,0.5)':eval=frame", '-metadata', 'artist=Secret artist',
        ]);

        $media = $this->processedUpload('song.flac', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame('audio', $media['kind']);
        self::assertEqualsWithDelta(300.0, $media['seconds'], 1.0);
        self::assertIsArray($media['peaks']);
        self::assertCount(200, $media['peaks']);
        self::assertContains(100, $media['peaks'], 'scaled to the loudest part');
        self::assertLessThan(5, $media['peaks'][50], 'the silent half');
        self::assertGreaterThan(90, $media['peaks'][150], 'the loud half');

        $audio = $this->variant($media, 'aac');
        $found = $this->probed($audio);
        self::assertIsArray($found['streams']);
        self::assertSame(['aac'], array_column($found['streams'], 'codec_name'));
        self::assertIsString($audio['path']);
        self::assertStringNotContainsString('Secret artist', (string) file_get_contents($audio['path']));
    }

    public function testAnMp4WithOnlySoundIsARecording(): void
    {
        $this->requireFfmpeg();
        $bytes = $this->ffmpegMakes('sound.mp4', ['-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-c:a', 'aac']);
        file_put_contents($this->dataDir . '/sound.mp4', $bytes);
        self::assertSame('video', FileType::of($this->dataDir . '/sound.mp4')['kind'], 'all the signature can say');

        $media = $this->processedUpload('sound.mp4', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame('audio', $media['kind']);
        $this->variant($media, 'aac');
    }

    public function testACoverPictureDoesNotMakeASongAVideo(): void
    {
        $this->requireFfmpeg();
        $this->requireTools();
        $cover = $this->dataDir . '/cover.jpg';
        shell_exec('vips black ' . escapeshellarg($cover) . ' 64 64 2>&1');
        $bytes = $this->ffmpegMakes('with-cover.mp3', [
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-i', $cover,
            '-map', '0:a', '-map', '1:v', '-c:v', 'copy', '-disposition:v', 'attached_pic', '-id3v2_version', '3',
        ]);
        unlink($cover);

        $media = $this->processedUpload('with-cover.mp3', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        self::assertSame('audio', $media['kind']);
    }

    public function testAFileThatOnlyLooksLikeAVideoFailsAtOnce(): void
    {
        $this->requireFfmpeg();

        $media = $this->processedUpload('fake.mp4', "\0\0\0\x20ftypisom" . random_bytes(2000));

        self::assertSame('failed', $media['status']);
        self::assertSame('This file could not be read as audio or video.', $media['error']);
        self::assertSame([], $media['variants']);
        $this->clock->advance(3600);
        self::assertFalse($this->service(JobRunner::class)->runNext(), 'trying again can\'t help');
    }

    public function testAMissingToolIsWorthRetrying(): void
    {
        $this->reboot(['FFPROBE_BIN' => 'no-such-tool-anywhere']);
        $media = $this->upload('a.mp4', "\0\0\0\x20ftypisom");
        $runner = $this->service(JobRunner::class);

        self::assertTrue($runner->runNext());
        $status = $this->service(MediaLibrary::class)->find(self::id($media));
        self::assertSame('processing', $status['status'] ?? null);

        $this->clock->advance(61);
        self::assertTrue($runner->runNext(), 'Ops can fix the path in time');
    }

    public function testATooLongVideoIsRefusedBeforeEncoding(): void
    {
        $this->requireFfmpeg();
        $this->reboot(['MEDIA_MAX_BYTES' => '40000000']);
        $bytes = $this->ffmpegMakes('long.mkv', ['-f', 'lavfi', '-i', 'color=size=64x64:rate=1:duration=600', '-c:v', 'libx264']);

        $media = $this->processedUpload('long.mkv', $bytes);

        self::assertSame('processing', $media['status'], 'a full disk can change, so this tries again');
        $job = $this->pdo->query('SELECT error FROM job');
        self::assertNotFalse($job);
        self::assertStringContainsString('limit', (string) $job->fetchColumn());
        self::assertSame([], $media['variants']);
    }

    public function testRetryingAFailedVideoRunsTheVideoJob(): void
    {
        $bytes = "\0\0\0\x20ftypisom";
        $media = $this->upload('a.mp4', $bytes);
        $this->pdo->exec('DELETE FROM job');
        $this->pdo->exec("UPDATE media SET status = 'failed', error = 'x'");

        $again = $this->upload('a.mp4', $bytes);

        self::assertSame(self::id($media), self::id($again));
        $type = $this->pdo->query('SELECT type FROM job');
        self::assertNotFalse($type);
        self::assertSame('av', $type->fetchColumn());
    }

    public function testReuploadingRescuesMediaStuckWithoutAJob(): void
    {
        $bytes = "\0\0\0\x20ftypisom";
        $this->upload('a.mp4', $bytes);
        $this->pdo->exec('DELETE FROM job');

        $this->upload('a.mp4', $bytes);
        $this->upload('a.mp4', $bytes);

        self::assertSame(1, $this->rows('job'), 'one job, and a job still queued needs no second one');
    }

    public function testFinishedFilesAreNotCountedAgainstTheFreeDisk(): void
    {
        $store = $this->service(MediaStore::class);
        $this->reboot(['MEDIA_MIN_FREE_BYTES' => (string) ($store->freeBytes() - 1_000_000_000)]);
        $media = $this->upload('notes.txt', 'just text');
        $file = $this->dataDir . '/made.mp4';
        file_put_contents($file, 'not really');

        $this->service(MediaLibrary::class)->publish(self::id($media), str_repeat('ab', 32), [
            ['preset' => 'h264', 'format' => 'mp4', 'width' => 2, 'height' => 2, 'bytes' => 2_000_000_000, 'file' => $file],
        ], 2, 2);

        self::assertSame('ready', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);
    }

    public function testFfmpegOnlyGetsLocalFilesAndTheChosenDemuxer(): void
    {
        $this->requireFfmpeg();
        $log = $this->dataDir . '/calls.log';
        $shim = function (string $tool) use ($log): string {
            $path = $this->dataDir . '/' . $tool . '-shim';
            file_put_contents($path, "#!/bin/sh\necho \"$tool \$*\" >>" . escapeshellarg($log) . "\nexec $tool \"\$@\"\n");
            chmod($path, 0o755);

            return $path;
        };
        $bytes = $this->ffmpegMakes('source.mkv', self::testVideoWithoutTags('320x240'));
        $this->reboot(['FFMPEG_BIN' => $shim('ffmpeg'), 'FFPROBE_BIN' => $shim('ffprobe')]);

        $media = $this->processedUpload('source.mkv', $bytes);

        self::assertSame('ready', $media['status'], (string) json_encode($media['error']));
        $calls = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($calls);
        self::assertGreaterThanOrEqual(4, count($calls));
        foreach ($calls as $call) {
            self::assertMatchesRegularExpression('/-protocol_whitelist file\b.*-f (matroska|mov)\b/', $call);
        }
        self::assertMatchesRegularExpression('/-threads 4 .*-i .*-threads 4 /', $calls[1], 'decoding is limited as well as encoding');
    }

    public function testATranscodeThatFailsIsNotRetriedForHours(): void
    {
        $this->requireFfmpeg();
        $bytes = $this->ffmpegMakes('source.mkv', self::testVideoWithoutTags('320x240'));
        $failing = $this->dataDir . '/failing-ffmpeg';
        file_put_contents($failing, "#!/bin/sh\necho nope >&2\nexit 1\n");
        chmod($failing, 0o755);
        $this->reboot(['FFMPEG_BIN' => $failing]);

        $media = $this->processedUpload('source.mkv', $bytes);

        self::assertSame('failed', $media['status']);
        self::assertStringContainsString('nope', (string) json_encode($media['error']));
    }

    public function testAToolThatExitsAsMissingIsNotTheFilesFault(): void
    {
        $missing = $this->dataDir . '/missing-tool';
        file_put_contents($missing, "#!/bin/sh\nexit 127\n");
        chmod($missing, 0o755);
        $this->reboot(['FFPROBE_BIN' => $missing]);
        $media = $this->upload('a.mp4', "\0\0\0\x20ftypisom");

        self::assertTrue($this->service(JobRunner::class)->runNext());

        self::assertSame('processing', $this->service(MediaLibrary::class)->find(self::id($media))['status'] ?? null);
    }

    public function testTheSecondWorkerTakesOnlyImages(): void
    {
        $queue = $this->service(JobQueue::class);
        $queue->enqueue('av', ['media_id' => 0]);
        $queue->enqueue('image', ['media_id' => 0]);

        $runner = $this->service(JobRunner::class);
        self::assertTrue($runner->runNext(true));
        self::assertFalse($runner->runNext(true), 'the video stays for the first worker');

        $left = $this->pdo->query("SELECT type FROM job WHERE state = 'queued'");
        self::assertNotFalse($left);
        self::assertSame('av', $left->fetchColumn());
        self::assertTrue($runner->runNext());
    }

    public function testVideoFilesAreServedInDevelopment(): void
    {
        $sha = str_repeat('ab', 32);
        $path = $this->service(MediaStore::class)->variantPath($sha, 'h264', 'mp4');
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, 'not really');

        $response = $this->get('/media/ab/ab/' . $sha . '-h264.mp4');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('video/mp4', $response->getHeaderLine('Content-Type'));
    }

    public function testWhileAVideoRunsTheNextWorkerOnlyDoesImagesAndNoHousekeeping(): void
    {
        $url = getenv('TEST_DATABASE_URL');
        self::assertIsString($url);
        $firstWorker = self::connectToTestDatabase($url);
        $firstWorker->query('SELECT pg_advisory_lock(7316001)');

        $queue = $this->service(JobQueue::class);
        $queue->enqueue('av', ['media_id' => 0]);
        self::assertNotNull($queue->claim(), 'the video the first worker is busy with');
        $queue->enqueue('av', ['media_id' => 0]);
        $queue->enqueue('image', ['media_id' => 0]);
        $this->clock->advance(7200);

        try {
            $tester = new CommandTester(new WorkerCommand($this->service(Services::class)));
            self::assertSame(0, $tester->execute(['--seconds' => '1']));
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock_all()');
        }

        $states = $this->pdo->query('SELECT type, state FROM job ORDER BY id');
        self::assertNotFalse($states);
        self::assertSame([
            ['type' => 'av', 'state' => 'running'],
            ['type' => 'av', 'state' => 'queued'],
            ['type' => 'image', 'state' => 'done'],
        ], $states->fetchAll(\PDO::FETCH_ASSOC), 'a video running for hours is not a dead worker');
    }
}
