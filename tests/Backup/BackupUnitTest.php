<?php

declare(strict_types=1);

namespace Edict\Tests\Backup;

use Edict\Backup\RestoreResult;
use Edict\Content\Body;
use Edict\Site\Settings;
use PHPUnit\Framework\TestCase;

final class BackupUnitTest extends TestCase
{
    public function testSettingsFromAnExportAreTypeChecked(): void
    {
        self::assertNull(Settings::importProblem([
            'title' => 'Logbook',
            'menu' => [['label' => 'Archive', 'url' => '/archive']],
            'logo' => 3,
            'logo_only' => true,
            'comment_words' => ['spam'],
            'something_newer' => ['ignored'],
        ]));
        self::assertSame('The setting "logo" has the wrong type.', Settings::importProblem(['logo' => '3']));
        self::assertSame('The setting "menu" has the wrong type.', Settings::importProblem(['menu' => [['label' => 'Archive']]]));
        self::assertSame('The setting "comment_words" has the wrong type.', Settings::importProblem(['comment_words' => [1]]));
        self::assertSame('The setting "comment_alerts" has the wrong type.', Settings::importProblem(['comment_alerts' => 'yes']));
    }

    public function testBodyProblemsAreTheEditorsOwn(): void
    {
        self::assertNull(Body::problem(['type' => 'doc', 'content' => []]));
        self::assertSame('A gallery can have up to 100 images.', Body::problem(['type' => 'doc', 'content' => [
            ['type' => 'gallery', 'attrs' => ['items' => array_fill(0, 101, ['mediaId' => 1])]],
        ]]));
        $deep = ['type' => 'paragraph'];
        for ($i = 0; $i < 120; ++$i) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        self::assertSame('The lists or quotes are nested too deeply.', Body::problem(['type' => 'doc', 'content' => [$deep]]));
    }

    public function testTheSummarySaysWhatCameBack(): void
    {
        $result = new RestoreResult(2, 1, 2, 3, 4, ['waves.mp4 (0123456789ab)'], true, ['A note.']);

        self::assertSame([
            '1 article, 2 pages, 3 tags restored.',
            '4 media files relinked, 1 missing.',
            'Settings restored.',
            'Not on disk, so skipped: waves.mp4 (0123456789ab).',
            'The worker is making thumbnails and other versions again; images appear as it gets to them.',
            'A note.',
        ], $result->lines());
    }
}
