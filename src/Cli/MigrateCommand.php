<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Database\Migrator;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Apply pending database migrations')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applied = $this->services->get(Migrator::class)->migrate();
        foreach ($applied as $version) {
            $output->writeln("Applied $version");
        }
        $output->writeln($applied === [] ? 'Nothing to do.' : sprintf('%d migration(s) applied.', count($applied)));

        return self::SUCCESS;
    }
}
