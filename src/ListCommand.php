<?php

namespace Osmianski\WorktreeManager;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('list')
            ->setDescription('List all worktrees');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            ensure_project_dir_is_git_repository(getcwd());

            $result = execute_git_command('git worktree list');

            if ($result['exitCode'] !== 0) {
                throw new WorktreeException(sprintf(
                    "Git command failed: %s",
                    $result['error']
                ));
            }

            $output->write($result['output']);

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln("<error>ERROR</error> {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
