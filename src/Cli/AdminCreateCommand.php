<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\AdminAccount;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:create', description: 'Create the admin account (there can be only one)')]
final class AdminCreateCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Admin username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');
        if (!is_string($username)) {
            return self::INVALID;
        }

        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }

        $password = CredentialPrompt::newPassword($helper, $input, $output);
        $secret = $this->services->get(AdminAccount::class)->create($username, $password);

        $output->writeln("Admin '" . AdminAccount::normaliseUsername($username) . "' created.");
        CredentialPrompt::showTotp($output, $secret, AdminAccount::normaliseUsername($username));

        return self::SUCCESS;
    }
}
