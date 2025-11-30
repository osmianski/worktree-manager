<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('up')
            ->setDescription('Start Docker containers using docker compose');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $worktreeConfig = load_worktree_config(getcwd());

            if (run_hook('up', $worktreeConfig, $output)) {
                $output->writeln('');
                $output->writeln('<info>✓ Containers started successfully</info>');
                return Command::SUCCESS;
            }

            $output->writeln('<info>Starting Docker containers...</info>');
            $process = run_script($output, 'docker compose up -d', getcwd());

            if (!$process->isSuccessful()) {
                throw new WorktreeException(
                    'Failed to start Docker containers',
                    sprintf(
                        "docker compose up -d failed with exit code %d\n%s",
                        $process->getExitCode(),
                        $process->getErrorOutput()
                    )
                );
            }

            $output->writeln('');
            $output->writeln('<info>✓ Containers started successfully</info>');

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
