<?php

declare(strict_types=1);

namespace Edict\Cli;

use Edict\Config;
use Edict\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(name: 'cache:warmup', description: 'Compile all templates, so that the web server needs no write access to the release')]
final class CacheWarmupCommand extends Command
{
    public function __construct(private readonly Services $services)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->services->get(Config::class);
        if ($config->cacheDir() === null) {
            $output->writeln('This environment does not cache templates.');

            return self::SUCCESS;
        }

        $root = $config->projectDir . '/templates';
        $twig = $this->services->get(Environment::class);
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $twig->load(str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)));
                ++$count;
            }
        }

        $output->writeln(sprintf('%d template(s) compiled.', $count));

        return self::SUCCESS;
    }
}
