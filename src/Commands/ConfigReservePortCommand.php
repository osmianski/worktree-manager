<?php

namespace Osmianski\WorktreeManager\Commands;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigReservePortCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('config:reserve-port')
            ->setDescription('Reserve a port in global configuration')
            ->addArgument('port', InputArgument::REQUIRED, 'Port number to reserve');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = (int)$input->getArgument('port');

        if ($port < 1024 || $port > 65535) {
            $output->writeln('<error>Port must be between 1024 and 65535</error>');
            return Command::FAILURE;
        }

        $globalConfig = load_global_config();
        $allocations = load_allocations();

        // Check if port is already reserved
        $reservedPorts = $globalConfig['reserved_ports'] ?? [];
        if (in_array($port, $reservedPorts)) {
            $output->writeln("<comment>Port {$port} is already reserved</comment>");
            return Command::SUCCESS;
        }

        // Find worktrees using this port
        $worktreesToReallocate = [];
        foreach ($allocations['allocations'] ?? [] as $worktree => $portMap) {
            foreach ($portMap as $varName => $allocatedPort) {
                if ($allocatedPort === $port) {
                    $worktreesToReallocate[] = [
                        'name' => $worktree,
                        'var' => $varName,
                        'port' => $allocatedPort,
                    ];
                }
            }
        }

        // Add port to reserved list
        $globalConfig['reserved_ports'][] = $port;
        save_global_config($globalConfig);

        $output->writeln("<info>✓ Port {$port} has been reserved</info>");

        // Reallocate worktrees if needed
        if (!empty($worktreesToReallocate)) {
            $output->writeln('');
            $output->writeln('<comment>The following worktrees are using this port and need reallocation:</comment>');
            foreach ($worktreesToReallocate as $item) {
                $output->writeln("  {$item['name']}: {$item['var']}={$item['port']}");
            }

            // Find and reallocate each worktree
            $rootPaths = $globalConfig['roots'] ?? [];
            $reallocated = 0;

            foreach ($worktreesToReallocate as $item) {
                $worktreePath = $this->findWorktreePath($item['name'], $rootPaths);

                if ($worktreePath) {
                    $output->writeln('');
                    $output->writeln("<info>Reallocating ports for {$item['name']}...</info>");

                    // Run allocate command in that directory
                    $result = $this->reallocateWorktree($worktreePath, $output);

                    if ($result === Command::SUCCESS) {
                        $reallocated++;
                    }
                }
                else {
                    $output->writeln("<error>Could not find worktree directory: {$item['name']}</error>");
                }
            }

            if ($reallocated > 0) {
                $output->writeln('');
                $output->writeln("<info>✓ Reallocated {$reallocated} worktree(s)</info>");
            }
        }

        return Command::SUCCESS;
    }

    protected function findWorktreePath(string $worktreeName, array $rootPaths): ?string
    {
        foreach ($rootPaths as $root) {
            $path = expand_path($root) . '/' . $worktreeName;
            if (is_dir($path) && file_exists($path . '/.worktree.yml')) {
                return $path;
            }
        }

        return null;
    }

    protected function reallocateWorktree(string $path, OutputInterface $output): int
    {
        $cwd = getcwd();

        try {
            chdir($path);

            // Force reallocation by removing existing allocations
            $allocations = load_allocations();
            $directoryName = basename($path);

            if (isset($allocations['allocations'][$directoryName])) {
                unset($allocations['allocations'][$directoryName]);
                save_allocations($allocations);
            }

            // Also remove .env file to force fresh allocation
            $envPath = $path . '/.env';
            if (file_exists($envPath)) {
                unlink($envPath);
            }

            $allocateCommand = new AllocateCommand();
            $allocateCommand->setApplication($this->getApplication());

            $input = new ArrayInput([]);
            $returnCode = $allocateCommand->run($input, $output);

            return $returnCode;
        }
        catch (RuntimeException $e) {
            $output->writeln("<error>Failed to reallocate: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
        finally {
            chdir($cwd);
        }
    }
}
