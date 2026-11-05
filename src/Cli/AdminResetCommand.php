<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\AdminAccount;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:reset', description: 'Set a new admin password and authenticator secret, and end all admin sessions')]
final class AdminResetCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }

        $password = CredentialPrompt::newPassword($helper, $input, $output);
        $result = $this->services->get(AdminAccount::class)->reset($password);

        $output->writeln("Credentials for '{$result['username']}' reset; all admin sessions ended.");
        CredentialPrompt::showTotp($output, $result['secret'], $result['username']);

        return self::SUCCESS;
    }
}
