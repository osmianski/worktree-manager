<?php

namespace Osmianski\WorktreeManager;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class NewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create new worktree and environment for it')
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'Branch to checkout in worktree', 'main')
            ->addOption('validate-ports', null, InputOption::VALUE_NONE, 'Validate that ports are actually available via socket check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentDir = getcwd();
            $branch = $input->getOption('branch');
            $validatePorts = $input->getOption('validate-ports');

            $output->writeln('<info>Checking git repository...</info>');
            $this->ensureProjectDirIsGitRepository($currentDir);
            $this->ensureBranchExists($branch);

            $output->writeln('<info>Loading configuration...</info>');
            $worktreesConfig = $this->loadWorktreesConfig($currentDir);
            $globalConfig = $this->loadGlobalConfig();
            $allocations = $this->loadAllocations();

            $worktreeName = $this->generateNextWorktreeName($currentDir);
            $worktreePath = dirname($currentDir) . '/' . $worktreeName;
            $output->writeln("<info>Next worktree: {$worktreeName}</info>");
            $this->ensureWorktreeDoesNotExist($worktreePath);

            $output->writeln('<info>Allocating ports...</info>');
            $portAllocations = $this->allocatePorts($worktreesConfig, $allocations, $globalConfig, $validatePorts);

            foreach ($portAllocations as $var => $port) {
                $output->writeln("  {$var}: {$port}");
            }

            $output->writeln('<info>Creating git worktree...</info>');
            $this->createWorktree($currentDir, $worktreePath, $branch);

            $output->writeln('<info>Generating .env file...</info>');
            $this->generateEnvFile($worktreePath, $portAllocations);

            $allocations['allocations'][$worktreeName] = $portAllocations;
            $this->saveAllocations($allocations);

            $installScript = $worktreePath . '/bin/install.sh';

            if (file_exists($installScript)) {
                $output->writeln('<info>Running install script...</info>');
                $this->runInstallScript($installScript, $output);
            }

            $output->writeln('');
            $output->writeln("<info>✓ Worktree created successfully at: {$worktreePath}</info>");

            return Command::SUCCESS;

        } catch (WorktreeException $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    // ========================================
    // Configuration Methods
    // ========================================

    protected function getHomeDirectory(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (!$home) {
            throw new WorktreeException('Could not determine home directory');
        }

        return $home;
    }

    protected function getConfigPath(): string
    {
        return $this->getHomeDirectory() . '/.config/worktree-manager/config.yml';
    }

    protected function getAllocationsPath(): string
    {
        return $this->getHomeDirectory() . '/.local/share/worktree-manager/allocations.json';
    }

    protected function loadWorktreesConfig(string $dir): array
    {
        $configPath = $dir . '/worktrees.yml';

        if (!file_exists($configPath)) {
            throw new WorktreeException(sprintf(
                "worktrees.yml not found in %s\nPlease create a worktrees.yml file with port configuration.\n\nExample:\nHTTP_PORT:\n  port_range: \"8000..\"\nVITE_PORT:\n  port_range: \"5173..\"",
                $dir
            ));
        }

        try {
            $config = Yaml::parseFile($configPath);
        }
        catch (Exception $e) {
            throw new WorktreeException(sprintf(
                "Invalid YAML syntax in worktrees.yml: %s",
                $e->getMessage()
            ));
        }

        $this->validateWorktreesConfig($config);
        return $config;
    }

    protected function loadGlobalConfig(): array
    {
        $configPath = $this->getConfigPath();

        if (!file_exists($configPath)) {
            // Create default config
            $defaultConfig = ['reserved_ports' => []];
            $this->ensureDirectoryExists(dirname($configPath));
            file_put_contents(
                $configPath,
                Yaml::dump($defaultConfig, 2, 2)
            );
            return $defaultConfig;
        }

        try {
            $config = Yaml::parseFile($configPath);
            return $config ?? ['reserved_ports' => []];
        }
        catch (Exception $e) {
            throw new WorktreeException(sprintf(
                "Invalid YAML syntax in config.yml: %s",
                $e->getMessage()
            ));
        }
    }

    protected function validateWorktreesConfig(array $config): void
    {
        if (empty($config)) {
            throw new WorktreeException('worktrees.yml is empty');
        }

        foreach ($config as $varName => $varConfig) {
            if (!is_array($varConfig) || !isset($varConfig['port_range'])) {
                throw new WorktreeException(sprintf(
                    "Invalid configuration for %s: expected 'port_range' key\n\nExample:\n%s:\n  port_range: \"8000..\"",
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

    // ========================================
    // Allocation Methods
    // ========================================

    protected function loadAllocations(): array
    {
        $allocationsPath = $this->getAllocationsPath();

        if (!file_exists($allocationsPath)) {
            return ['allocations' => []];
        }

        $json = file_get_contents($allocationsPath);
        $allocations = json_decode($json, true);

        if ($allocations === null) {
            throw new WorktreeException(sprintf(
                "Invalid JSON in allocations.json: %s",
                json_last_error_msg()
            ));
        }

        return $allocations;
    }

    protected function saveAllocations(array $allocations): void
    {
        $allocationsPath = $this->getAllocationsPath();
        $this->ensureDirectoryExists(dirname($allocationsPath));

        $json = json_encode($allocations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Atomic write: write to temp file, then rename
        $tempPath = $allocationsPath . '.tmp';
        file_put_contents($tempPath, $json);
        rename($tempPath, $allocationsPath);
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

    // ========================================
    // Port Allocation Methods
    // ========================================

    protected function allocatePorts(array $config, array $allocations, array $globalConfig, bool $validate): array
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

    // ========================================
    // Worktree Management Methods
    // ========================================

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

    protected function ensureProjectDirIsGitRepository(string $path): void
    {
        if (!is_dir($path . '/.git') || is_file($path . '/.git')) {
            throw new WorktreeException(sprintf(
                "Not a git repository: %s\nThe current directory must be a git repository to create worktrees.",
                $path
            ));
        }
    }

    protected function ensureBranchExists(string $branch): void
    {
        $result = $this->executeGitCommand("git rev-parse --verify {$branch}");

        if ($result['exitCode'] !== 0) {
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

    protected function createWorktree(string $sourcePath, string $targetPath, string $branch): void
    {
        $targetName = basename($targetPath);
        $result = $this->executeGitCommand("git worktree add ../{$targetName} {$branch}");

        if ($result['exitCode'] !== 0) {
            throw new WorktreeException(sprintf(
                "Git command failed: %s",
                $result['error']
            ));
        }
    }

    protected function executeGitCommand(string $command): array
    {
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['exitCode' => 1, 'output' => '', 'error' => 'Failed to execute command'];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'output' => $output,
            'error' => $error
        ];
    }

    // ========================================
    // File Generation Methods
    // ========================================

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

    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    // ========================================
    // Install Script Methods
    // ========================================

    protected function runInstallScript(string $scriptPath, OutputInterface $output): void
    {
        $workingDir = dirname($scriptPath);
        $command = "cd " . escapeshellarg($workingDir) . " && bash bin/install.sh 2>&1";

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);

            while ($line = fgets($pipes[1])) {
                $output->write("  " . $line);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                $output->writeln("<comment>Warning: install.sh exited with code {$exitCode}</comment>");
                $output->writeln("<comment>You may need to run it manually:</comment>");
                $output->writeln("<comment>  cd {$workingDir} && bash bin/install.sh</comment>");
            }
        }
    }
}
