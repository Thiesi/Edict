<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Config;
use Edict\Database\Row;

/**
 * Makes the public versions of audio and video with ffmpeg: formats every browser
 * plays, without the original's metadata (a phone video knows where it was filmed).
 * The original stays private.
 *
 * ffmpeg is a large parser fed with uploaded files, so it reads only local files, and
 * only with the demuxer matching what FileType found, never what it guesses by itself.
 */
final readonly class AvProcessor
{
    public const JOB = 'av';
    public const POSTER_JOB = 'poster';

    private const DEMUXERS = [
        'mp4' => 'mov', 'mov' => 'mov', 'm4a' => 'mov', 'mkv' => 'matroska', 'avi' => 'avi',
        'mp3' => 'mp3', 'aac' => 'aac', 'flac' => 'flac', 'wav' => 'wav', 'ogg' => 'ogg',
    ];

    private const UNDECODABLE_AUDIO = ['none', 'unknown', 'apac'];

    private const QUIET = ['-nostdin', '-hide_banner', '-loglevel', 'error', '-y'];

    /** Portrait or landscape, at most 1080p, never enlarged, even sizes as H.264 needs. */
    public const SCALE = "scale=w='min(iw,if(gt(iw,ih),1920,1080))':h='min(ih,if(gt(iw,ih),1080,1920))'"
        . ':force_original_aspect_ratio=decrease:force_divisible_by=2';

    /**
     * HDR footage, the default on recent phones, must become ordinary video, or it looks
     * flat or glaring. ToneMap's table does the work, in 16-bit RGB. The HDR side data goes
     * too: left on an ordinary stream, it can make players treat it as HDR.
     */
    private const TONE_MAP = ',scale=in_color_matrix=bt2020,format=gbrp16le,lut3d=file=%s:interp=tetrahedral'
        . ',scale=out_color_matrix=bt709:out_range=tv,format=yuv420p'
        // The frames would still claim the source's HDR transfer and BT.2020, and those win over -color_trc.
        . ',setparams=color_primaries=bt709:color_trc=bt709:colorspace=bt709:range=tv'
        . ',sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA,sidedata=mode=delete:type=CONTENT_LIGHT_LEVEL'
        . ',sidedata=mode=delete:type=DYNAMIC_HDR_PLUS,sidedata=mode=delete:type=DOVI_RPU_BUFFER,sidedata=mode=delete:type=DOVI_METADATA';
    private const SDR_TAGS = [
        '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709', '-color_range', 'tv',
        // Drops SEI messages, where H.264 would carry mastering display and light levels.
        '-bsf:v', 'filter_units=remove_types=6',
    ];

    private const AUDIO_BITRATE = '192k';
    private const VIDEO_AUDIO_BITRATE = '160k';
    private const VIDEO_MAX_BITRATE = '8M';
    private const VIDEO_BUFFER = '16M';

    /** A different codec can need more than the original, but rarely this much more. */
    private const EXPECTED_GROWTH = 3;

    /** The most these settings produce per second, with some room for the container. */
    private const VIDEO_BYTES_PER_SECOND = 1_150_000;
    private const AUDIO_BYTES_PER_SECOND = 27_000;
    private const SMALLEST_OUTPUT_LIMIT = 50_000_000;

    private const MAX_AUDIO_BYTES = 500_000_000;

    /** The host has other sites on it, and this runs for hours. In practice it takes up to 6 cores. */
    private const THREADS = '4';
    private const PROBE_SECONDS = 120;
    private const TRANSCODE_SECONDS = 6 * 3600;
    private const POSTER_AT = 0.1;
    private const POSTER_SECONDS = 300;

    private const PEAKS = 200;
    private const PEAK_SAMPLE_RATE = '4000';
    private const READ_BYTES = 1_048_576;

    public function __construct(
        private \PDO $pdo,
        private MediaStore $store,
        private MediaLibrary $library,
        private Config $config,
    ) {}

    /**
     * @throws MediaError
     */
    public function process(int $mediaId): void
    {
        $stmt = $this->pdo->prepare('SELECT sha256, extension, bytes FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch();
        if (!is_array($media)) {
            return; // Deleted since the job was queued.
        }
        $sha256 = Row::str($media, 'sha256');
        $bytes = Row::int($media, 'bytes');
        $demuxer = self::DEMUXERS[Row::str($media, 'extension')] ?? throw new MediaError('This file type can\'t be played.', true);

        $dir = $this->store->newJobDir();
        try {
            // Read in place: copying a 4 GB original first would only cost time and disk.
            $source = $this->store->originalPath($sha256);
            try {
                $probe = $this->probe($source, $demuxer, $dir);
            } catch (MediaError $e) {
                throw $e->hopeless || Command::failedToStart($e) ? $e : new MediaError('This file could not be read as audio or video.', true);
            }

            if ($probe['video']) {
                $kind = 'video';
                $done = $this->video($source, $demuxer, $dir, $bytes, $probe);
            } elseif ($probe['audio'] !== null) {
                if ($bytes > self::MAX_AUDIO_BYTES) {
                    throw new MediaError('Audio files can be up to ' . (self::MAX_AUDIO_BYTES / 1_000_000) . ' MB.', true);
                }
                $kind = 'audio';
                $done = $this->audio($source, $demuxer, $dir, $bytes, $probe['audio'], $probe['seconds']);
            } else {
                throw new MediaError('This file contains neither sound nor a picture.', true);
            }

            $this->library->publish($mediaId, $sha256, $done['variants'], $done['width'], $done['height'], $kind, $done['meta']);
        } finally {
            MediaStore::remove($dir);
        }
    }

    /**
     * @param array{video: bool, audio: int|null, seconds: float, width: int, height: int, hdr: string|null} $probe
     *
     * @return array{variants: list<array{preset: string, format: string, width: int, height: int, bytes: int, file: string}>, width: int, height: int, meta: array<string, mixed>}
     */
    private function video(string $source, string $demuxer, string $dir, int $bytes, array $probe): array
    {
        $limit = $this->outputLimit($probe['seconds'], self::VIDEO_BYTES_PER_SECOND, $bytes);
        $output = $dir . '/h264.mp4';
        $this->encode([
            ...$this->reading($demuxer, $source),
            '-map', '0:V:0', ...($probe['audio'] === null ? [] : ['-map', '0:' . $probe['audio']]),
            '-map_metadata', '-1', '-map_chapters', '-1', '-sn', '-dn',
            '-vf', self::SCALE . ($probe['hdr'] === null ? '' : $this->toneMap($probe['hdr'], $dir)),
            ...($probe['hdr'] === null ? [] : self::SDR_TAGS),
            '-c:v', 'libx264', '-preset', 'faster', '-crf', '23', '-maxrate', self::VIDEO_MAX_BITRATE, '-bufsize', self::VIDEO_BUFFER,
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', self::VIDEO_AUDIO_BITRATE, '-ac', '2',
            '-threads', self::THREADS, '-movflags', '+faststart', '-fs', (string) $limit,
            $output,
        ], $dir);
        $size = self::sizeWithin($output, $limit);

        $made = $this->probe($output, 'mov', $dir);
        $poster = $this->poster($output, $dir, $made['seconds']);

        return [
            'variants' => [
                ['preset' => 'h264', 'format' => 'mp4', 'width' => $made['width'], 'height' => $made['height'], 'bytes' => $size, 'file' => $output],
                ['preset' => 'poster', 'format' => 'jpg', 'width' => $made['width'], 'height' => $made['height'], 'bytes' => self::sizeWithin($poster, PHP_INT_MAX), 'file' => $poster],
            ],
            'width' => $made['width'],
            'height' => $made['height'],
            'meta' => ['seconds' => round($made['seconds'], 2)],
        ];
    }

    /**
     * The frame the author picked in the editor, from the public video, so it matches
     * what plays: upright, tone-mapped, scaled.
     *
     * @throws MediaError
     */
    public function choosePoster(int $mediaId, float $seconds): void
    {
        $stmt = $this->pdo->prepare("SELECT sha256 FROM media WHERE id = ? AND kind = 'video' AND status = 'ready'");
        $stmt->execute([$mediaId]);
        $sha256 = $stmt->fetchColumn();
        $video = is_string($sha256) ? (array_column($this->library->variants($mediaId, $sha256), null, 'preset')['h264'] ?? null) : null;
        if (!is_string($sha256) || $video === null) {
            return; // Deleted or being processed again since the job was queued.
        }

        $dir = $this->store->newJobDir();
        try {
            $file = $this->store->variantPath($sha256, 'h264', 'mp4');
            $poster = $dir . '/poster.jpg';
            try {
                $this->takeFrame($file, $poster, $seconds, $dir);
            } catch (MediaError) {
                // ffmpeg calls an empty result an error. The next lines decide.
            }
            // Past the last frame's start there is nothing to seek to. The end is what was meant.
            if (!is_file($poster) || filesize($poster) === 0) {
                $this->takeLastFrame($file, $poster, $dir);
            }
            if (!is_file($poster) || filesize($poster) === 0) {
                throw new MediaError('There is no picture at that point of the video.', true);
            }

            $this->library->replacePoster($mediaId, $sha256, MediaLibrary::posterPreset($seconds), $poster, $video['width'], $video['height']);
        } finally {
            MediaStore::remove($dir);
        }
    }

    /**
     * The filters that make HDR ordinary, with the table written into the job dir.
     */
    private function toneMap(string $transfer, string $dir): string
    {
        $table = str_replace('\\', '/', $dir) . '/tonemap.cube';
        // Inside a filter graph these would need escaping twice over; job dirs never have them.
        if (preg_match('#^[A-Za-z0-9_./-]+$#', $table) !== 1) {
            throw new MediaError('The job folder has characters ffmpeg filters cannot take: ' . $table);
        }
        if (file_put_contents($table, ToneMap::cube($transfer)) === false) {
            throw new MediaError('Could not write the colour table.');
        }

        return sprintf(self::TONE_MAP, $table);
    }

    /**
     * @return array{variants: list<array{preset: string, format: string, width: int, height: int, bytes: int, file: string}>, width: int, height: int, meta: array<string, mixed>}
     */
    private function audio(string $source, string $demuxer, string $dir, int $bytes, int $stream, float $seconds): array
    {
        $limit = $this->outputLimit($seconds, self::AUDIO_BYTES_PER_SECOND, $bytes);
        $output = $dir . '/aac.m4a';
        $this->encode([
            ...$this->reading($demuxer, $source),
            '-map', '0:' . $stream, '-map_metadata', '-1', '-map_chapters', '-1', '-vn', '-sn', '-dn',
            '-c:a', 'aac', '-b:a', self::AUDIO_BITRATE, '-ac', '2',
            '-movflags', '+faststart', '-fs', (string) $limit,
            $output,
        ], $dir);
        $size = self::sizeWithin($output, $limit);

        return [
            'variants' => [['preset' => 'aac', 'format' => 'm4a', 'width' => 0, 'height' => 0, 'bytes' => $size, 'file' => $output]],
            'width' => 0,
            'height' => 0,
            'meta' => ['seconds' => round($this->probe($output, 'mov', $dir)['seconds'], 2), 'peaks' => $this->peaks($output, $dir)],
        ];
    }

    /**
     * A failure after hours of work isn't worth two more tries. Uploading the file again
     * tries again.
     *
     * @param list<string> $command
     */
    private function encode(array $command, string $dir): void
    {
        try {
            Command::run($command, self::TRANSCODE_SECONDS, $dir);
        } catch (MediaError $e) {
            throw $e->hopeless || Command::failedToStart($e) ? $e : new MediaError($e->getMessage(), true);
        }
    }

    /**
     * Where ffmpeg has to stop: what these settings can produce for this length, in
     * case the file lied about its length, and never into the free disk everyone else
     * needs. What's checked in advance is what it will likely need, since reserving the
     * worst case would refuse a long, quiet lecture that needs a fraction of it.
     */
    private function outputLimit(float $seconds, int $bytesPerSecond, int $sourceBytes): int
    {
        $worst = $seconds > 0 ? (int) ceil($seconds * $bytesPerSecond) : $sourceBytes * self::EXPECTED_GROWTH;
        $worst = max(self::SMALLEST_OUTPUT_LIMIT, $worst);
        $this->library->assertRoomFor(min($worst, max(self::SMALLEST_OUTPUT_LIMIT, $sourceBytes * self::EXPECTED_GROWTH)));

        return min($worst, $this->store->freeBytes() - $this->config->mediaMinFreeBytes);
    }

    private static function sizeWithin(string $file, int $limit): int
    {
        $bytes = @filesize($file);
        if ($bytes === false || $bytes === 0) {
            throw new MediaError('The tool finished without producing a file.');
        }
        // ffmpeg stops at the limit and calls that a success, with half a video.
        if ($bytes >= $limit) {
            throw new MediaError('The result grew larger than its length or the free disk allows, so it was stopped.', true);
        }

        return $bytes;
    }

    private function poster(string $video, string $dir, float $seconds): string
    {
        $poster = $dir . '/poster.jpg';
        try {
            $this->takeFrame($video, $poster, $seconds * self::POSTER_AT, $dir);
        } catch (MediaError) {
            // Some videos have no picture to seek to there. The next line decides.
        }
        if (!is_file($poster) || filesize($poster) === 0) {
            $this->takeFrame($video, $poster, 0.0, $dir);
        }

        return $poster;
    }

    private function takeFrame(string $video, string $poster, float $at, string $dir): void
    {
        Command::run([
            $this->config->ffmpegBin, ...self::QUIET, '-protocol_whitelist', 'file', '-ss', sprintf('%.3F', $at), '-f', 'mov', '-i', $video,
            '-map', '0:V:0', '-frames:v', '1', '-q:v', '3', '-map_metadata', '-1', '-update', '1', $poster,
        ], self::POSTER_SECONDS, $dir);
    }

    private function takeLastFrame(string $video, string $poster, string $dir): void
    {
        Command::run([
            $this->config->ffmpegBin, ...self::QUIET, '-protocol_whitelist', 'file', '-sseof', '-1', '-f', 'mov', '-i', $video,
            '-map', '0:V:0', '-q:v', '3', '-map_metadata', '-1', '-update', '1', $poster,
        ], self::POSTER_SECONDS, $dir);
    }

    /**
     * How loud each part of the recording is, from 0 to 100, for the waveform. Read in
     * pieces, since a long recording doesn't fit into PHP's memory.
     *
     * @return list<int>
     */
    private function peaks(string $audio, string $dir): array
    {
        $samples = $dir . '/samples.raw';
        Command::run([
            $this->config->ffmpegBin, ...self::QUIET, '-protocol_whitelist', 'file', '-f', 'mov', '-i', $audio,
            '-map', '0:a:0', '-ac', '1', '-ar', self::PEAK_SAMPLE_RATE, '-f', 's16le', $samples,
        ], self::TRANSCODE_SECONDS, $dir);

        $total = (int) floor((int) @filesize($samples) / 2);
        $stream = @fopen($samples, 'r');
        if ($stream === false || $total === 0) {
            return [];
        }

        $count = min(self::PEAKS, $total);
        $buckets = array_fill(0, $count, 0);
        $position = 0;
        while ($position < $total && ($read = fread($stream, self::READ_BYTES)) !== false && $read !== '') {
            foreach (unpack('s*', $read) ?: [] as $value) {
                if (is_int($value) && $position < $total) {
                    $bucket = (int) ($position * $count / $total);
                    $buckets[$bucket] = max($buckets[$bucket], abs($value));
                    ++$position;
                }
            }
        }
        fclose($stream);

        $loudest = max(1, ...$buckets);

        return array_values(array_map(static fn(int $peak): int => (int) round($peak * 100 / $loudest), $buckets));
    }

    /**
     * @return list<string>
     */
    private function reading(string $demuxer, string $source): array
    {
        return [$this->config->ffmpegBin, ...self::QUIET, '-threads', self::THREADS, '-protocol_whitelist', 'file', '-f', $demuxer, '-i', $source];
    }

    /**
     * @return array{video: bool, audio: int|null, seconds: float, width: int, height: int, hdr: string|null} audio is the stream to use
     */
    private function probe(string $file, string $demuxer, string $dir): array
    {
        $output = Command::run([
            $this->config->ffprobeBin, '-v', 'error', '-protocol_whitelist', 'file', '-f', $demuxer,
            '-show_entries', 'format=duration:stream=index,codec_type,codec_name,width,height,color_transfer:stream_disposition=attached_pic',
            '-of', 'json', $file,
        ], self::PROBE_SECONDS, $dir);

        $found = json_decode($output, true);
        $format = is_array($found) && is_array($found['format'] ?? null) ? $found['format'] : [];
        $streams = is_array($found) && is_array($found['streams'] ?? null) ? $found['streams'] : [];

        $result = [
            'video' => false,
            'audio' => null,
            'seconds' => is_numeric($format['duration'] ?? null) ? (float) $format['duration'] : 0.0,
            'width' => 0,
            'height' => 0,
            'hdr' => null,
        ];
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $disposition = $stream['disposition'] ?? null;
            // An album cover counts as a video stream, but it doesn't make a video.
            $cover = is_array($disposition) && ($disposition['attached_pic'] ?? 0) === 1;
            if (($stream['codec_type'] ?? null) === 'audio') {
                // The first one that can be decoded: a phone's spatial track may come first.
                $codec = $stream['codec_name'] ?? null;
                if ($result['audio'] === null && is_int($stream['index'] ?? null) && is_string($codec) && !in_array($codec, self::UNDECODABLE_AUDIO, true)) {
                    $result['audio'] = $stream['index'];
                }
            } elseif (($stream['codec_type'] ?? null) === 'video' && !$cover && !$result['video']) {
                $result['video'] = true;
                $result['width'] = is_int($stream['width'] ?? null) ? $stream['width'] : 0;
                $result['height'] = is_int($stream['height'] ?? null) ? $stream['height'] : 0;
                $transfer = $stream['color_transfer'] ?? null;
                $result['hdr'] = is_string($transfer) && ToneMap::isHdr($transfer) ? $transfer : null;
            }
        }

        return $result;
    }
}
