<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\Accounts;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:identities', description: 'List the Google and Twitch accounts allowed to sign in as admin')]
final class AdminIdentitiesCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $identities = $this->services->get(Accounts::class)->adminIdentities();
        } catch (\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        if ($identities === []) {
            $output->writeln('No accounts are allow-listed.');
        }
        foreach ($identities as $identity) {
            $output->writeln($identity['provider'] . ' ' . $identity['subject']);
        }

        return self::SUCCESS;
    }
}
