<?php

namespace Osmianski\WorktreeManager\Commands;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setDescription('Remove a worktree by its number')
            ->addArgument('number', InputArgument::REQUIRED, 'Worktree number (e.g., 2 for lp-2)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force removal even if there are modified or untracked files');
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

            // Check if Docker containers exist and destroy them
            $this->cleanupDockerContainers($worktreePath, $output);

            $force = $input->getOption('force');
            $command = $force ? "git worktree remove --force {$worktreePath}" : "git worktree remove {$worktreePath}";
            $result = run($command);

            if ($result->getExitCode() !== 0) {
                $errorOutput = trim($result->getErrorOutput());

                // Check if the error is due to modified or untracked files
                if (!$force && (str_contains($errorOutput, 'modified or untracked files') || str_contains($errorOutput, 'use --force'))) {
                    $output->writeln("<error>ERROR</error> The worktree contains modified or untracked files:\n");

                    // Show git status for the worktree
                    $statusResult = run("git -C {$worktreePath} status --short");
                    if ($statusResult->getExitCode() === 0) {
                        $statusOutput = trim($statusResult->getOutput());
                        if ($statusOutput) {
                            $output->writeln($statusOutput);
                        }
                    }

                    $output->writeln("\nOptions:");
                    $output->writeln("  1. Review and commit/stash changes: cd {$worktreePath} && git status");
                    $output->writeln("  2. Force removal: worktree remove {$number} --force");

                    return Command::FAILURE;
                }

                throw new WorktreeException(sprintf(
                    "Git command failed: %s",
                    $errorOutput
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

    protected function cleanupDockerContainers(string $worktreePath, OutputInterface $output): void
    {
        // Check if docker-compose.yml exists
        if (!file_exists($worktreePath . '/docker-compose.yml')) {
            return;
        }

        // Check if any containers exist
        $process = run('docker compose ps --format json', $worktreePath);

        if (!$process->isSuccessful()) {
            // Can't check for containers, skip cleanup
            return;
        }

        $containers = array_filter(
            explode("\n", trim($process->getOutput())),
            fn($line) => !empty($line)
        );

        if (empty($containers)) {
            // No containers to clean up
            return;
        }

        $output->writeln("<info>Stopping and removing Docker containers...</info>");

        // Run docker compose down with -v to remove volumes as well
        $downProcess = run_script($output, 'docker compose down -v', $worktreePath);

        if (!$downProcess->isSuccessful()) {
            $output->writeln(sprintf(
                "<comment>Warning: Failed to stop Docker containers (exit code %d)</comment>",
                $downProcess->getExitCode()
            ));
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
