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
            $output->writeln('<info>Waiting for containers to be healthy...</info>');
            $this->waitForContainersHealthy($output);

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

    protected function waitForContainersHealthy(OutputInterface $output, int $maxWaitSeconds = 30): void
    {
        $startTime = time();

        while (time() - $startTime < $maxWaitSeconds) {
            $process = run('docker compose ps --format json');

            if (!$process->isSuccessful()) {
                // If we can't check status, just wait a fixed amount
                sleep(5);
                return;
            }

            $containers = array_filter(
                explode("\n", trim($process->getOutput())),
                fn($line) => !empty($line)
            );

            if (empty($containers)) {
                // No containers found, nothing to wait for
                return;
            }

            $allRunning = true;
            $notReadyContainers = [];

            foreach ($containers as $containerJson) {
                $container = json_decode($containerJson, true);

                if (!$container) {
                    continue;
                }

                $state = $container['State'] ?? '';
                $health = $container['Health'] ?? '';

                // Container is ready if it's running AND either:
                // 1. Has no health check, or
                // 2. Health check reports "healthy"
                $isRunning = $state === 'running';
                $isHealthy = $health === '' || $health === 'healthy';

                if (!$isRunning || !$isHealthy) {
                    $allRunning = false;
                    $name = $container['Name'] ?? 'unknown';
                    $notReadyContainers[] = $name . " (state: {$state}" . ($health ? ", health: {$health}" : '') . ")";
                }
            }

            if ($allRunning) {
                return;
            }

            sleep(1);
        }

        // Timeout reached, but don't fail - just warn
        if (!empty($notReadyContainers)) {
            $output->writeln(sprintf(
                '<comment>Warning: Some containers may not be fully ready after %d seconds:</comment>',
                $maxWaitSeconds
            ));
            foreach ($notReadyContainers as $containerInfo) {
                $output->writeln("  <comment>- {$containerInfo}</comment>");
            }
        }
    }
}
