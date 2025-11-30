<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create new worktree and environment for it')
            ->addOption('branch', 'b', InputOption::VALUE_OPTIONAL, 'Branch to checkout in worktree (if not specified, creates detached HEAD)')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base branch to create worktree from', 'main')
            ->addOption('install', 'i', InputOption::VALUE_NONE, 'Run install command in new worktree')
            ->addOption('migrate', 'm', InputOption::VALUE_NONE, 'Run migrate command in new worktree')
            ->addOption('validate-ports', null, InputOption::VALUE_NONE, 'Validate that ports are actually available via socket check')
            ->setAliases(['add']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentDir = getcwd();
            $branch = $input->getOption('branch');
            $base = $input->getOption('base');
            $install = $input->getOption('install');
            $migrate = $input->getOption('migrate');
            $validatePorts = $input->getOption('validate-ports');

            $output->writeln('<info>Checking git repository...</info>');
            ensure_project_dir_is_git_repository($currentDir);
            $this->ensureBranchExists($base);

            $output->writeln('<info>Loading configuration...</info>');
            $worktreeConfig = load_worktree_config($currentDir);
            $globalConfig = load_global_config();
            $allocations = load_allocations();

            $worktreeName = $this->generateNextWorktreeName($currentDir);
            $worktreePath = dirname($currentDir) . '/' . $worktreeName;
            $output->writeln("<info>Next worktree: {$worktreeName}</info>");
            $this->ensureWorktreeDoesNotExist($worktreePath);

            $output->writeln('<info>Allocating ports...</info>');
            $portAllocations = $this->allocatePorts($worktreeConfig['environment'] ?? [], $allocations, $globalConfig, $validatePorts);

            foreach ($portAllocations as $var => $port) {
                $output->writeln("  {$var}: {$port}");
            }

            $output->writeln('<info>Creating git worktree...</info>');
            $this->createWorktree($worktreePath, $base, $branch);

            $output->writeln('<info>Generating .env file...</info>');
            generate_env_file($worktreePath, $portAllocations);

            $allocations['allocations'][$worktreeName] = $portAllocations;
            save_allocations($allocations);

            if ($install) {
                $output->writeln('');
                $output->writeln('<info>Running install...</info>');
                run_install($this->getApplication(), $output, $worktreePath);
            }

            if ($migrate) {
                $output->writeln('');
                $output->writeln('<info>Running migrations...</info>');
                run_migrations($this->getApplication(), $output, $worktreePath);
            }

            $output->writeln('');
            $output->writeln("<info>✓ Worktree created successfully at: {$worktreePath}</info>");

            return Command::SUCCESS;

        } catch (WorktreeException $e) {
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

    protected function allocatePorts(array $config, array $allocations, array $globalConfig, bool $validate): array
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
                $usedPorts,
                $validate
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

    protected function findNextAvailablePort(int $start, ?int $end, array $usedPorts, bool $validate): ?int
    {
        $maxPort = $end ?? 65535;

        for ($port = $start; $port <= $maxPort; $port++) {
            if (in_array($port, $usedPorts)) {
                continue;
            }

            if ($validate && !$this->isPortAvailable($port)) {
                continue;
            }

            return $port;
        }

        return null;
    }

    protected function isPortAvailable(int $port): bool
    {
        // Try to bind to the port to check if it's available
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return true; // Assume available if we can't check
        }

        $result = @socket_bind($socket, '127.0.0.1', $port);
        socket_close($socket);

        return $result !== false;
    }

    protected function generateNextWorktreeName(string $currentPath): string
    {
        $baseName = basename($currentPath);
        $parentDir = dirname($currentPath);

        if (!is_dir($parentDir)) {
            throw new WorktreeException(sprintf('Parent directory does not exist: %s', $parentDir));
        }

        // Find all directories in parent that match pattern: {baseName}-{number}
        $siblings = [];
        $entries = scandir($parentDir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $parentDir . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            // Check if matches pattern: baseName-number
            if (preg_match('/^' . preg_quote($baseName, '/') . '-(\d+)$/', $entry, $matches)) {
                $siblings[] = (int)$matches[1];
            }
        }

        // Find next number
        if (empty($siblings)) {
            return $baseName . '-2';
        }

        $maxNumber = max($siblings);
        return $baseName . '-' . ($maxNumber + 1);
    }

    protected function ensureBranchExists(string $branch): void
    {
        $result = run("git rev-parse --verify {$branch}");

        if ($result->getExitCode() !== 0) {
            throw new WorktreeException(sprintf(
                "Branch '%s' does not exist\n\nTo create it, run:\n  git branch %s",
                $branch,
                $branch
            ));
        }
    }

    protected function ensureWorktreeDoesNotExist(string $worktreePath): void
    {
        if (file_exists($worktreePath)) {
            throw new WorktreeException(sprintf(
                "Worktree already exists at %s\nPlease remove the existing worktree first:\n  git worktree remove %s",
                $worktreePath,
                $worktreePath
            ));
        }
    }

    protected function createWorktree(string $targetPath, string $base, ?string $branch): void
    {
        $targetName = basename($targetPath);

        if ($branch === null) {
            // Create worktree in detached HEAD state
            $result = run("git worktree add --detach ../{$targetName} {$base}");
        }
        else {
            // Validate that the branch exists
            $this->ensureBranchExists($branch);

            // Create worktree with specified branch
            $result = run("git worktree add -b {$branch} ../{$targetName} {$base}");
        }

        if ($result->getExitCode() !== 0) {
            throw new WorktreeException(sprintf(
                "Git command failed: %s",
                $result->getErrorOutput()
            ));
        }
    }
}
