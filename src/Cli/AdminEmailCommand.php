<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Auth\AdminAccount;
use Edict\Mail\SecurityNotice;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:email', description: 'Set the admin\'s notification address (comment alerts, security notices); "" removes it')]
final class AdminEmailCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('address', InputArgument::REQUIRED, 'An email address, or "" to fall back to ALERT_EMAIL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getArgument('address');
        if (!is_string($address)) {
            return self::INVALID;
        }
        $admin = $this->services->get(AdminAccount::class);
        $old = $admin->email();

        try {
            $new = $admin->setEmail($address);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
        if ($new !== $old) {
            $this->services->get(SecurityNotice::class)->record('changed the notification address on the command line', $old);
        }

        $output->writeln($new === null ? 'Notification address removed; ALERT_EMAIL applies, if set.' : "Notifications now go to $new.");

        return self::SUCCESS;
    }
}
