<?php

namespace Osmianski\WorktreeManager\Commands;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RootListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('root:list')
            ->setDescription('List registered project root directories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = load_global_config();

            if (!isset($config['roots']) || empty($config['roots'])) {
                $output->writeln("<comment>No roots registered</comment>");
                $output->writeln("\nRegister project directories with:");
                $output->writeln("  worktree root:add <directory>");
                return Command::SUCCESS;
            }

            $output->writeln("<info>Registered project roots:</info>");
            foreach ($config['roots'] as $root) {
                $output->writeln("  {$root}");
            }

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
