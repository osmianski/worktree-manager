<?php

namespace Osmianski\WorktreeManager\Commands;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setDescription('Remove a worktree by its number')
            ->addArgument('number', InputArgument::REQUIRED, 'Worktree number (e.g., 2 for lp-2)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentDir = getcwd();
            ensure_project_dir_is_git_repository($currentDir);

            $number = $input->getArgument('number');

            if (!is_numeric($number)) {
                throw new WorktreeException(sprintf(
                    "Invalid worktree number: %s\nPlease provide a numeric worktree number (e.g., 2 for lp-2)",
                    $number
                ));
            }

            $baseName = basename($currentDir);
            $worktreeName = "{$baseName}-{$number}";
            $worktreePath = dirname($currentDir) . '/' . $worktreeName;

            // Check if worktree exists
            if (!is_dir($worktreePath)) {
                throw new WorktreeException(sprintf(
                    "Worktree does not exist: %s",
                    $worktreePath
                ));
            }

            $output->writeln("<info>Removing worktree: {$worktreeName}</info>");

            $result = run("git worktree remove {$worktreePath}");

            if ($result->getExitCode() !== 0) {
                throw new WorktreeException(sprintf(
                    "Git command failed: %s",
                    $result->getErrorOutput()
                ));
            }

            // Remove allocations from the allocations file
            $this->removeAllocations($worktreeName);

            $output->writeln("<info>✓ Worktree removed successfully: {$worktreeName}</info>");

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln("<error>ERROR</error> {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function removeAllocations(string $worktreeName): void
    {
        $allocationsPath = get_allocations_path();

        if (!file_exists($allocationsPath)) {
            return;
        }

        $json = file_get_contents($allocationsPath);
        $allocations = json_decode($json, true);

        if ($allocations === null) {
            return;
        }

        if (isset($allocations['allocations'][$worktreeName])) {
            unset($allocations['allocations'][$worktreeName]);

            $json = json_encode($allocations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Atomic write: write to temp file, then rename
            $tempPath = $allocationsPath . '.tmp';
            file_put_contents($tempPath, $json);
            rename($tempPath, $allocationsPath);
        }
    }
}
