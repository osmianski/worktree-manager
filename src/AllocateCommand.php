<?php

namespace Osmianski\WorktreeManager;

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
            $worktreesConfig = $this->loadProjectConfig($currentDir);
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
            $portAllocations = $this->allocatePorts($worktreesConfig, $allocations, $globalConfig);

            foreach ($portAllocations as $var => $port) {
                $output->writeln("  {$var}: {$port}");
            }

            $output->writeln('<info>Generating .env file...</info>');
            $this->generateEnvFile($currentDir, $portAllocations);

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

    protected function loadProjectConfig(string $dir): array
    {
        $filename = '.worktree.yml';
        $configPath = "{$dir}/{$filename}";

        if (!file_exists($configPath)) {
            throw new WorktreeException(
                sprintf("%s not found in %s", $filename, $dir),
                sprintf(
                    "Please create a %s file with port configuration.\n\nExample:\nenvironment:\n  HTTP_PORT:\n    port_range: \"8000..\"\n  VITE_PORT:\n    port_range: \"5173..\"",
                    $filename,
                ));
        }

        try {
            $config = Yaml::parseFile($configPath);
        }
        catch (Exception $e) {
            throw new WorktreeException(sprintf(
                "Invalid YAML syntax in .worktree.yml: %s",
                $e->getMessage()
            ));
        }

        // Extract environment section if present
        if (isset($config['environment']) && is_array($config['environment'])) {
            $config = $config['environment'];
        }

        $this->validateProjectConfig($config);
        return $config;
    }

    protected function validateProjectConfig(array $config): void
    {
        if (empty($config)) {
            throw new WorktreeException('.worktree.yml is empty');
        }

        foreach ($config as $varName => $varConfig) {
            if (!is_array($varConfig) || !isset($varConfig['port_range'])) {
                throw new WorktreeException(sprintf(
                    "Invalid configuration for %s: expected 'port_range' key\n\nExample:\nenvironment:\n  %s:\n    port_range: \"8000..\"",
                    $varName,
                    $varName
                ));
            }

            // Validate port range format
            $this->parsePortRange($varConfig['port_range'], $varName);
        }
    }

    protected function parsePortRange(mixed $value, string $varName = null): array
    {
        if (!is_string($value)) {
            throw new WorktreeException(sprintf(
                "Invalid port range for %s: must be a string in format \"8000..\" or \"8000..9000\"",
                $varName ?? 'port'
            ));
        }

        if (!preg_match('/^(\d+)\.\.(\d*)$/', $value, $matches)) {
            throw new WorktreeException(sprintf(
                "Invalid port range format for %s: expected \"number..\" or \"number..number\", got \"%s\"",
                $varName ?? 'port',
                $value
            ));
        }

        $start = (int)$matches[1];
        $end = $matches[2] !== '' ? (int)$matches[2] : null;

        if ($start < 1024 || $start > 65535) {
            throw new WorktreeException(sprintf(
                "Port range start must be between 1024 and 65535, got %d",
                $start
            ));
        }

        if ($end !== null && ($end < $start || $end > 65535)) {
            throw new WorktreeException(sprintf(
                "Port range end must be between %d and 65535, got %d",
                $start,
                $end
            ));
        }

        return ['start' => $start, 'end' => $end];
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
            $range = $this->parsePortRange($varConfig['port_range'], $varName);

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

    protected function generateEnvFile(string $path, array $ports): void
    {
        $lines = [];
        foreach ($ports as $var => $port) {
            $lines[] = "{$var}={$port}";
        }

        $content = implode("\n", $lines) . "\n";
        $envPath = $path . '/.env';

        // Atomic write
        $tempPath = $envPath . '.tmp';
        file_put_contents($tempPath, $content);
        rename($tempPath, $envPath);
    }
}
