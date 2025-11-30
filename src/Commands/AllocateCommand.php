<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class AllocateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('allocate')
            ->setDescription('Allocate ports for current directory and create .env file')
            ->addArgument(
                'assignments',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Port assignments in the format VAR_NAME=PORT (e.g., DB_PORT=33060 REDIS_PORT=6379)'
            )
            ->addOption('install', 'i', InputOption::VALUE_NONE, 'Run install command after allocating ports')
            ->addOption('up', 'u', InputOption::VALUE_NONE, 'Restart Docker containers after allocating ports')
            ->addOption('migrate', 'm', InputOption::VALUE_NONE, 'Run migrate command after allocating ports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentDir = getcwd();

            $output->writeln('<info>Loading configuration...</info>');
            $worktreeConfig = load_worktree_config($currentDir);
            $globalConfig = load_global_config();
            $allocations = load_allocations();

            $directoryName = basename($currentDir);

            // Parse port assignments from command line arguments
            $manualAssignments = $this->parseAssignments($input->getArgument('assignments'));

            // Validate manual assignments against worktree config
            if (!empty($manualAssignments)) {
                $this->validateAssignments($manualAssignments, $worktreeConfig, $output);
            }

            // Get existing allocations for this directory
            $existingAllocations = $allocations['allocations'][$directoryName] ?? [];

            // Determine which ports need to be allocated/removed
            $requestedVars = array_keys($worktreeConfig['environment'] ?? []);
            $allocatedVars = array_keys($existingAllocations);
            $newVars = array_diff($requestedVars, $allocatedVars);
            $removedVars = array_diff($allocatedVars, $requestedVars);

            // Save original allocations for display purposes
            $originalAllocations = $existingAllocations;

            // Refresh existing allocations from .env file if it exists
            $envPath = $currentDir . '/.env';
            if (file_exists($envPath)) {
                $envVars = parse_env_file($envPath);
                $existingAllocations = [];
                foreach ($requestedVars as $varName) {
                    if (isset($envVars[$varName])) {
                        $existingAllocations[$varName] = (int)$envVars[$varName];
                    }
                }
            }

            // Start with existing allocations
            $portAllocations = $existingAllocations;
            $changed = false;

            // Remove ports that are no longer in config
            if (!empty($removedVars)) {
                $output->writeln('<info>Removing obsolete port allocations...</info>');
                foreach ($removedVars as $var) {
                    $output->writeln("  {$var}: {$originalAllocations[$var]}");
                    unset($portAllocations[$var]);
                    unset($allocations['allocations'][$directoryName][$var]);
                }
                $changed = true;
            }

            // Allocate new ports
            if (!empty($newVars)) {
                $output->writeln('<info>Allocating new ports...</info>');
                $newPortConfig = array_intersect_key(
                    $worktreeConfig['environment'] ?? [],
                    array_flip($newVars)
                );
                $newPortAllocations = $this->allocatePorts($newPortConfig, $allocations, $globalConfig, $manualAssignments);

                foreach ($newPortAllocations as $var => $port) {
                    $output->writeln("  {$var}: {$port}");
                }

                $portAllocations = array_merge($portAllocations, $newPortAllocations);
                $changed = true;
            }

            // Apply manual assignments to existing allocations if provided
            if (!empty($manualAssignments)) {
                $usedPorts = array_merge(
                    $this->getAllocatedPorts($allocations),
                    $this->getReservedPorts($globalConfig)
                );

                // Exclude current worktree's ports from used ports
                foreach ($portAllocations as $port) {
                    $usedPorts = array_diff($usedPorts, [$port]);
                }

                foreach ($manualAssignments as $var => $port) {
                    if (isset($portAllocations[$var]) && $portAllocations[$var] !== $port) {
                        // Check if the new port is available
                        if (in_array($port, $usedPorts)) {
                            throw new WorktreeException(sprintf(
                                "Port %d for %s is already allocated or reserved",
                                $port,
                                $var
                            ));
                        }

                        $output->writeln("<info>Updating {$var}: {$portAllocations[$var]} → {$port}</info>");
                        $portAllocations[$var] = $port;
                        $changed = true;
                    }
                }
            }

            // Display current allocations if nothing changed
            if (!$changed && !empty($portAllocations)) {
                $output->writeln("<comment>Ports already allocated for {$directoryName}</comment>");
                $output->writeln("\nCurrent allocations:");
                foreach ($portAllocations as $var => $port) {
                    $output->writeln("  {$var}: {$port}");
                }
                $output->writeln('');
            }

            // Generate .env file
            if (!empty($portAllocations)) {
                $output->writeln('<info>Generating .env file...</info>');
                generate_env_file($currentDir, $portAllocations);
            }

            // Save allocations if changed
            if ($changed) {
                $allocations['allocations'][$directoryName] = $portAllocations;
                save_allocations($allocations);
            }

            $output->writeln("<info>✓ Ports allocated successfully for: {$directoryName}</info>");

            // Run install command if requested
            if ($input->getOption('install')) {
                $output->writeln('');
                $output->writeln('<info>Running install...</info>');
                run_install($this->getApplication(), $output);
            }

            // Restart Docker containers if requested
            if ($input->getOption('up')) {
                $output->writeln('');
                $output->writeln('<info>Restarting Docker containers...</info>');
                run_down($this->getApplication(), $output);
                run_up($this->getApplication(), $output);
            }

            // Run migrate command if requested
            if ($input->getOption('migrate')) {
                $output->writeln('');
                $output->writeln('<info>Running migrations...</info>');
                run_migrations($this->getApplication(), $output);
            }

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln("\n<error>ERROR</error> {$e->getMessage()}\n");

            if ($e->getDescription()) {
                $output->writeln($e->getDescription());
            }

            return Command::FAILURE;
        }
    }

    protected function getAllocatedPorts(array $allocations): array
    {
        $ports = [];

        if (isset($allocations['allocations'])) {
            foreach ($allocations['allocations'] as $worktree => $portMap) {
                foreach ($portMap as $var => $port) {
                    $ports[] = $port;
                }
            }
        }

        return array_unique($ports);
    }

    protected function getReservedPorts(array $globalConfig): array
    {
        $ports = [];

        if (!isset($globalConfig['reserved_ports']) || !is_array($globalConfig['reserved_ports'])) {
            return $ports;
        }

        foreach ($globalConfig['reserved_ports'] as $item) {
            if (is_int($item)) {
                $ports[] = $item;
            }
            elseif (is_string($item) && str_contains($item, '-')) {
                // Expand range like "5000-5010"
                $parts = explode('-', $item);
                if (count($parts) === 2) {
                    $start = (int)$parts[0];
                    $end = (int)$parts[1];
                    $ports = array_merge($ports, range($start, $end));
                }
            }
            elseif (is_string($item)) {
                // Single port as string
                $ports[] = (int)$item;
            }
        }

        return $ports;
    }

    protected function allocatePorts(array $config, array $allocations, array $globalConfig, array $manualAssignments = []): array
    {
        $usedPorts = array_merge(
            $this->getAllocatedPorts($allocations),
            $this->getReservedPorts($globalConfig)
        );

        $portAllocations = [];

        foreach ($config as $varName => $varConfig) {
            // Use manual assignment if provided
            if (isset($manualAssignments[$varName])) {
                $port = $manualAssignments[$varName];

                // Validate that the port is not already allocated to another worktree
                if (in_array($port, $usedPorts)) {
                    throw new WorktreeException(sprintf(
                        "Port %d for %s is already allocated or reserved",
                        $port,
                        $varName
                    ));
                }

                $portAllocations[$varName] = $port;
                $usedPorts[] = $port;
                continue;
            }

            // Auto-allocate port from range
            $range = parse_port_range($varConfig['port_range'], $varName);

            $port = $this->findNextAvailablePort(
                $range['start'],
                $range['end'],
                $usedPorts
            );

            if ($port === null) {
                $rangeStr = $range['start'] . '..' . ($range['end'] ?? '');
                throw new WorktreeException(sprintf(
                    "No available ports in range %s for %s\nAll ports in this range are allocated or reserved.",
                    $rangeStr,
                    $varName
                ));
            }

            $portAllocations[$varName] = $port;
            $usedPorts[] = $port; // Mark as used for subsequent allocations
        }

        return $portAllocations;
    }

    protected function findNextAvailablePort(int $start, ?int $end, array $usedPorts): ?int
    {
        $maxPort = $end ?? 65535;

        for ($port = $start; $port <= $maxPort; $port++) {
            if (in_array($port, $usedPorts)) {
                continue;
            }

            return $port;
        }

        return null;
    }

    protected function parseAssignments(array $arguments): array
    {
        $assignments = [];

        foreach ($arguments as $arg) {
            if (!preg_match('/^([A-Z_][A-Z0-9_]*)=(\d+)$/', $arg, $matches)) {
                throw new WorktreeException(sprintf(
                    "Invalid assignment format: %s\nExpected format: VAR_NAME=PORT (e.g., DB_PORT=33060)",
                    $arg
                ));
            }

            $varName = $matches[1];
            $port = (int)$matches[2];

            if ($port < 1024 || $port > 65535) {
                throw new WorktreeException(sprintf(
                    "Port must be between 1024 and 65535, got %d for %s",
                    $port,
                    $varName
                ));
            }

            $assignments[$varName] = $port;
        }

        return $assignments;
    }

    protected function validateAssignments(array $assignments, array $worktreeConfig, $output): void
    {
        $configuredVars = array_keys($worktreeConfig['environment'] ?? []);

        foreach ($assignments as $varName => $port) {
            if (!in_array($varName, $configuredVars)) {
                $output->writeln("<comment>Warning: {$varName} is not defined in .worktree.yml</comment>");
            }
        }
    }
}
