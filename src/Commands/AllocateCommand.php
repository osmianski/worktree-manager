<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class AllocateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('allocate')
            ->setDescription('Allocate ports for current directory and create .env file');
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

            // Check if already allocated
            if (isset($allocations['allocations'][$directoryName])) {
                $output->writeln("<comment>Ports already allocated for {$directoryName}</comment>");
                $output->writeln("\nCurrent allocations:");
                foreach ($allocations['allocations'][$directoryName] as $var => $port) {
                    $output->writeln("  {$var}: {$port}");
                }
                return Command::SUCCESS;
            }

            $output->writeln('<info>Allocating ports...</info>');
            $portAllocations = $this->allocatePorts($worktreeConfig['environment'] ?? [], $allocations, $globalConfig);

            foreach ($portAllocations as $var => $port) {
                $output->writeln("  {$var}: {$port}");
            }

            $output->writeln('<info>Generating .env file...</info>');
            generate_env_file($currentDir, $portAllocations);

            $allocations['allocations'][$directoryName] = $portAllocations;
            save_allocations($allocations);

            $output->writeln('');
            $output->writeln("<info>✓ Ports allocated successfully for: {$directoryName}</info>");

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

    protected function allocatePorts(array $config, array $allocations, array $globalConfig): array
    {
        $usedPorts = array_merge(
            $this->getAllocatedPorts($allocations),
            $this->getReservedPorts($globalConfig)
        );

        $portAllocations = [];

        foreach ($config as $varName => $varConfig) {
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
}
