<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\Accounts;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:allow', description: 'Allow a Google or Twitch account to sign in as admin')]
final class AdminAllowCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, implode(' or ', ProviderRegistry::NAMES))
            ->addArgument('id', InputArgument::REQUIRED, 'The account\'s provider ID, shown on /account after signing in with it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $input->getArgument('provider');
        $id = $input->getArgument('id');
        if (!is_string($provider) || !is_string($id) || $id === '' || !in_array($provider, ProviderRegistry::NAMES, true)) {
            $output->writeln('<error>Provider must be one of: ' . implode(', ', ProviderRegistry::NAMES) . '</error>');

            return self::INVALID;
        }

        try {
            $result = $this->services->get(Accounts::class)->allowForAdmin($provider, $id);
        } catch (\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(match ($result) {
            'added' => "This $provider account can now sign in as admin.",
            'unchanged' => "This $provider account was already allow-listed.",
            'moved' => "This $provider account can now sign in as admin. It was moved from a reader account, whose sessions have ended.",
        });

        return self::SUCCESS;
    }
}
