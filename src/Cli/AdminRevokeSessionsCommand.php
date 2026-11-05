<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\SessionStore;
use Edict\Database\Row;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:revoke-sessions', description: 'End all admin sessions, e.g. after a lost device')]
final class AdminRevokeSessionsCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stmt = $this->services->get(\PDO::class)->query('SELECT id FROM app_user WHERE is_admin');
        $row = $stmt === false ? false : $stmt->fetch();
        if (!is_array($row)) {
            $output->writeln('No admin exists yet.');

            return self::FAILURE;
        }

        $count = $this->services->get(SessionStore::class)->destroyAllForUser(Row::int($row, 'id'));
        $output->writeln(sprintf('%d session(s) ended.', $count));

        return self::SUCCESS;
    }
}
