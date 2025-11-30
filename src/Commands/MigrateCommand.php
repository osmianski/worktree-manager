<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('migrate')
            ->setDescription('Run database migrations for the project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $worktreeConfig = load_worktree_config(getcwd());

            // Run migrate hook if it exists
            if (run_hook('migrate', $worktreeConfig, $output)) {
                $output->writeln('');
                $output->writeln('<info>✓ Migration completed successfully</info>');
                return Command::SUCCESS;
            }

            // Auto-detect project type and migrate
            $output->writeln('<info>Detecting project type...</info>');
            $project = detect_project();

            if (!$project) {
                throw new WorktreeException(
                    "Unknown project type",
                    "Could not detect project type for migration.\n\n" .
                    "To use a custom migration method, add one of:\n" .
                    "  - hooks/migrate in .worktree.yml\n" .
                    "  - .worktree/hooks/migrate executable file"
                );
            }

            $projectType = basename(str_replace('\\', '/', get_class($project)));
            $output->writeln("<info>✓ Detected: {$projectType}</info>");

            $project->migrate($output);

            $output->writeln('');
            $output->writeln('<info>✓ Migration completed successfully</info>');

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
