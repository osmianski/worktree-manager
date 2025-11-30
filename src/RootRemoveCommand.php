<?php

namespace Osmianski\WorktreeManager;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RootRemoveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('root:remove')
            ->setDescription('Unregister a project root directory')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory path to unregister');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $directory = $input->getArgument('directory');

            // Expand ~ to home directory
            $directory = expand_path($directory);

            // Get absolute path if it exists
            if (is_dir($directory)) {
                $directory = realpath($directory);
            }

            $config = load_global_config();

            if (!isset($config['roots']) || empty($config['roots'])) {
                $output->writeln("<comment>No roots registered</comment>");
                return Command::SUCCESS;
            }

            $originalCount = count($config['roots']);
            $config['roots'] = array_values(array_filter(
                $config['roots'],
                fn($root) => $root !== $directory
            ));

            if (count($config['roots']) === $originalCount) {
                $output->writeln("<comment>Root not found: {$directory}</comment>");
                return Command::SUCCESS;
            }

            save_global_config($config);

            $output->writeln("<info>✓ Root removed: {$directory}</info>");

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
