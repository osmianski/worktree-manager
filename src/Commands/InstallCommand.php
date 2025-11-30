<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Install dependencies for the project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentDir = getcwd();

            // 1. Check .worktree.yml for hooks/install
            $worktreeConfig = load_worktree_config($currentDir);

            if (isset($worktreeConfig['hooks']['install'])) {
                $output->writeln('<info>Running install hook from .worktree.yml...</info>');
                $this->runHook($worktreeConfig['hooks']['install'], $currentDir, $output);
                $output->writeln('');
                $output->writeln('<info>✓ Installation completed successfully</info>');
                return Command::SUCCESS;
            }

            // 2. Check .worktree/hooks/install file
            $hookPath = $currentDir . '/.worktree/hooks/install';

            if (file_exists($hookPath)) {
                $output->writeln('<info>Running install hook from .worktree/hooks/install...</info>');
                $this->runHook($hookPath, $currentDir, $output);
                $output->writeln('');
                $output->writeln('<info>✓ Installation completed successfully</info>');
                return Command::SUCCESS;
            }

            // 3. Auto-detect project type and install
            $output->writeln('<info>Detecting project type...</info>');
            $project = detect_project($currentDir);

            if (!$project) {
                throw new WorktreeException(
                    "Unknown project type",
                    "Could not detect project type for installation.\n\n" .
                    "To use a custom installation method, add one of:\n" .
                    "  - hooks/install in .worktree.yml\n" .
                    "  - .worktree/hooks/install executable file"
                );
            }

            $projectType = basename(str_replace('\\', '/', get_class($project)));
            $output->writeln("<info>✓ Detected: {$projectType}</info>");

            $project->install($output);

            $output->writeln('');
            $output->writeln('<info>✓ All installations completed successfully</info>');

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln('');
            $output->writeln("<error>ERROR</error> {$e->getMessage()}");

            if ($e->getDescription()) {
                $output->writeln('');
                $output->writeln($e->getDescription());
            }

            $output->writeln('');

            return Command::FAILURE;
        }
        catch (Exception $e) {
            $output->writeln('');
            $output->writeln("<error>ERROR</error> {$e->getMessage()}");
            $output->writeln('');

            return Command::FAILURE;
        }
    }

    protected function runHook(mixed $hookValue, string $workingDir, OutputInterface $output): void
    {
        $commands = is_array($hookValue) ? $hookValue : [$hookValue];

        foreach ($commands as $command) {
            // Check if it's a file path
            $filePath = $workingDir . '/' . $command;

            if (file_exists($filePath)) {
                // Execute as file (must be executable)
                $output->writeln("  Executing: {$command}");
                $process = run($filePath, $workingDir);
            }
            else {
                // Execute as shell command
                $output->writeln("  Running: {$command}");
                $process = run($command, $workingDir);
            }

            if (!$process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    "Command failed with exit code %d: %s\n%s",
                    $process->getExitCode(),
                    $command,
                    $process->getErrorOutput()
                ));
            }
        }
    }
}
