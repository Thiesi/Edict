<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Clock;
use Edict\Database\Row;
use Edict\Media\JobRunner;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Started by cron every minute instead of running as a daemon: it always runs the
 * current release, recovers from crashes by itself, and needs no privileges to
 * restart after a deploy. The database lock means runs can't overlap, so a long job
 * simply makes the next few starts exit at once.
 */
#[AsCommand(name: 'worker', description: 'Run background jobs for a while, then exit (meant for cron)')]
final class WorkerCommand extends Command
{
    private const LOCK = 7_316_001;
    private const POLL_SECONDS = 2;
    private const TEMP_FILES_LIVE_FOR = 86400;

    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'How long to keep looking for jobs', '55');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->services->get(MediaStore::class);
        if (!$store->isConfigured()) {
            return self::SUCCESS;
        }

        $pdo = $this->services->get(\PDO::class);
        $lock = $pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK . ') AS locked');
        $row = $lock === false ? false : $lock->fetch();
        if (!is_array($row) || !Row::bool($row, 'locked')) {
            return self::SUCCESS;
        }

        $runner = $this->services->get(JobRunner::class);
        $clock = $this->services->get(Clock::class);

        $runner->housekeeping();
        $staleBefore = $clock->now() - self::TEMP_FILES_LIVE_FOR;
        $store->sweep($staleBefore);
        $this->services->get(MediaLibrary::class)->forgetUploadsOlderThan($staleBefore);

        $seconds = $input->getOption('seconds');
        $deadline = time() + (is_numeric($seconds) ? (int) $seconds : 55);
        while (time() < $deadline) {
            if (!$runner->runNext()) {
                sleep(self::POLL_SECONDS);
            }
        }

        return self::SUCCESS;
    }
}
