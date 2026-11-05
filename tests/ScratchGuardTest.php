<?php

declare(strict_types=1);

namespace Edict\Tests;

use PHPUnit\Framework\TestCase;

final class ScratchGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // System temp dir, removed in tearDown (CLAUDE.md rules 1 and 2).
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edict-guard-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.scratch/nested', 0o777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function makeFiles(string $dir, int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            touch($this->root . "/$dir/f$i");
        }
    }

    public function testPassesUnderLimit(): void
    {
        $this->makeFiles('.scratch', 3);
        $this->makeFiles('.scratch/nested', 2);

        self::assertNull(ScratchGuard::check($this->root, 5));
    }

    public function testRefusesOverLimitAcrossDirs(): void
    {
        mkdir($this->root . '/.phpunit.cache');
        $this->makeFiles('.scratch/nested', 4);
        $this->makeFiles('.phpunit.cache', 2);

        $error = ScratchGuard::check($this->root, 5);

        self::assertNotNull($error);
        self::assertStringContainsString('clean_scratch.py', $error);
    }

    public function testIgnoresNonScratchDirs(): void
    {
        mkdir($this->root . '/src');
        $this->makeFiles('src', 10);

        self::assertNull(ScratchGuard::check($this->root, 5));
    }

    public function testCountStopsAtCap(): void
    {
        $this->makeFiles('.scratch', 10);

        self::assertSame(4, ScratchGuard::countFiles($this->root . '/.scratch', 3));
    }
}
