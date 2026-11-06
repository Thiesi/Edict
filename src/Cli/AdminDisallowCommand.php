<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\Accounts;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:disallow', description: 'Stop a Google or Twitch account from signing in as admin; ends all admin sessions')]
final class AdminDisallowCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'google or twitch')
            ->addArgument('id', InputArgument::REQUIRED, 'The account\'s provider ID, as listed by admin:identities');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $input->getArgument('provider');
        $id = $input->getArgument('id');
        if (!is_string($provider) || !is_string($id)) {
            return self::INVALID;
        }

        try {
            $removed = $this->services->get(Accounts::class)->disallowForAdmin($provider, $id);
        } catch (\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        if (!$removed) {
            $output->writeln('<error>That account is not allow-listed.</error>');

            return self::FAILURE;
        }

        $output->writeln('Removed. All admin sessions have ended.');

        return self::SUCCESS;
    }
}
