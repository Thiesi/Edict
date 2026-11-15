<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Backup\Restore;
use Edict\Backup\RestoreRefused;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'content:restore', description: 'Restore a JSON export (from the dashboard) into an install without articles, pages or media')]
final class ContentRestoreCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The export file, edict-export-YYYY-MM-DD.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (!is_string($file)) {
            return self::INVALID;
        }

        try {
            $result = $this->services->get(Restore::class)->fromFile($file);
        } catch (RestoreRefused $e) {
            $output->writeln('<error>Nothing was restored. ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('Restored from a version ' . $result->version . ' export.');
        foreach ($result->lines() as $line) {
            $output->writeln($line);
        }

        return self::SUCCESS;
    }
}
