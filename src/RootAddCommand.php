<?php

namespace Osmianski\WorktreeManager;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RootAddCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('root:add')
            ->setDescription('Register a project root directory for port scanning')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory path to register');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $directory = $input->getArgument('directory');

            // Expand ~ to home directory
            $directory = expand_path($directory);

            // Validate directory exists
            if (!is_dir($directory)) {
                throw new WorktreeException(sprintf(
                    "Directory does not exist: %s",
                    $directory
                ));
            }

            // Get absolute path
            $directory = realpath($directory);

            $config = load_global_config();

            if (!isset($config['roots'])) {
                $config['roots'] = [];
            }

            // Check if already added
            if (in_array($directory, $config['roots'])) {
                $output->writeln("<comment>Root already registered: {$directory}</comment>");
                return Command::SUCCESS;
            }

            $config['roots'][] = $directory;
            save_global_config($config);

            $output->writeln("<info>✓ Root added: {$directory}</info>");
            $output->writeln('');
            $output->writeln("To scan this root and sync port allocations, run:");
            $output->writeln("  worktree scan");

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln("<error>ERROR</error> {$e->getMessage()}");

            if ($e->getDescription()) {
                $output->writeln($e->getDescription());
            }

            return Command::FAILURE;
        }
    }
}
