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
            $worktreeConfig = load_worktree_config(getcwd());

            // Run install hook if it exists
            if (run_hook('install', $worktreeConfig, $output)) {
                run_hook('post_install', $worktreeConfig, $output);
                $output->writeln('');
                $output->writeln('<info>✓ Installation completed successfully</info>');
                return Command::SUCCESS;
            }

            // Auto-detect project type and install
            $output->writeln('<info>Detecting project type...</info>');
            $project = detect_project();

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

            run_hook('post_install', $worktreeConfig, $output);

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
}
