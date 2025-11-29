<?php

namespace Osmianski\WorktreeManager;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new worktree with allocated ports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentDir = getcwd();
        $output->writeln("Creating new worktree from: {$currentDir}");

        // TODO: Implement worktree creation logic

        return Command::SUCCESS;
    }
}
