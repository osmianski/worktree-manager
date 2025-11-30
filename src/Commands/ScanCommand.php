<?php

namespace Osmianski\WorktreeManager\Commands;

use Exception;
use Osmianski\WorktreeManager\Exception\WorktreeException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('scan')
            ->setDescription('Scan registered roots and sync port allocations with reality')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be scanned without updating allocations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $dryRun = $input->getOption('dry-run');

            $output->writeln('<info>Loading configuration...</info>');
            $config = load_global_config();

            if (empty($config['roots'])) {
                $output->writeln("<comment>No roots registered</comment>");
                $output->writeln("\nRegister project directories with:");
                $output->writeln("  worktree root:add <directory>");

                return Command::FAILURE;
            }

            $output->writeln('<info>Scanning registered roots...</info>');
            foreach ($config['roots'] as $root) {
                $output->writeln("  {$root}");
            }
            $output->writeln('');

            $newAllocations = ['allocations' => []];
            $projects = [];
            $totalWorktrees = 0;
            $totalPorts = 0;
            $errors = [];
            $warnings = [];

            // Scan all roots
            foreach ($config['roots'] as $root) {
                if (!is_dir($root)) {
                    $warnings[] = "Root directory does not exist: {$root}";
                    $warnings[] = "  Consider running: worktree root:remove {$root}";
                    continue;
                }

                $directories = find_directories_with_worktree_config($root);

                foreach ($directories as $dir) {
                    $dirName = basename($dir);

                    // Load .worktree.yml to know expected variables
                    try {
                        $worktreeConfig = $this->loadProjectConfig($dir);
                    }
                    catch (WorktreeException $e) {
                        $errors[] = "Failed to load .worktree.yml in {$dir}: {$e->getMessage()}";
                        continue;
                    }

                    // Check if .env exists
                    $envPath = $dir . '/.env';
                    if (!file_exists($envPath)) {
                        $errors[] = "No .env file found in {$dir}";
                        $errors[] = "  Run: cd {$dir} && worktree allocate";
                        continue;
                    }

                    // Parse .env file
                    try {
                        $envVars = $this->parseEnvFile($envPath);
                    }
                    catch (Exception $e) {
                        $errors[] = "Failed to parse .env in {$dir}: {$e->getMessage()}";
                        continue;
                    }

                    // Extract port allocations
                    $portAllocations = [];
                    foreach ($worktreeConfig as $varName => $varConfig) {
                        if (!isset($envVars[$varName])) {
                            $warnings[] = "Missing {$varName} in {$dir}/.env";
                            continue;
                        }

                        $port = (int)$envVars[$varName];
                        $portAllocations[$varName] = $port;
                        $totalPorts++;
                    }

                    if (!empty($portAllocations)) {
                        $newAllocations['allocations'][$dirName] = $portAllocations;
                        $totalWorktrees++;

                        // Group by project
                        $projectKey = $this->getProjectKey($dir);
                        if (!isset($projects[$projectKey])) {
                            $projects[$projectKey] = [];
                        }
                        $projects[$projectKey][] = [
                            'name' => $dirName,
                            'path' => $dir,
                            'ports' => $portAllocations,
                        ];
                    }
                }
            }

            // Show found projects
            if (!empty($projects)) {
                $output->writeln('<info>Found projects:</info>');
                foreach ($projects as $projectPath => $worktrees) {
                    $output->writeln("  {$projectPath}");
                    foreach ($worktrees as $worktree) {
                        $portStr = implode(', ', array_map(
                            fn($k, $v) => "{$k}={$v}",
                            array_keys($worktree['ports']),
                            array_values($worktree['ports'])
                        ));
                        $output->writeln("    - {$worktree['name']}: {$portStr}");
                    }
                }
                $output->writeln('');
            }

            // Check for port conflicts
            $conflicts = $this->findPortConflicts($newAllocations);
            if (!empty($conflicts)) {
                $output->writeln('<error>Port conflicts detected:</error>');
                foreach ($conflicts as $port => $directories) {
                    $output->writeln("  Port {$port} used by:");
                    foreach ($directories as $dir) {
                        $output->writeln("    - {$dir}");
                    }
                }
                $output->writeln('');
            }

            // Show warnings
            if (!empty($warnings)) {
                $output->writeln('<comment>Warnings:</comment>');
                foreach ($warnings as $warning) {
                    $output->writeln("  {$warning}");
                }
                $output->writeln('');
            }

            // Show errors
            if (!empty($errors)) {
                $output->writeln('<error>Errors:</error>');
                foreach ($errors as $error) {
                    $output->writeln("  {$error}");
                }
                $output->writeln('');
            }

            // Summary
            $output->writeln("<info>Total: {$totalWorktrees} worktrees, {$totalPorts} ports</info>");

            // Save allocations
            if (!$dryRun) {
                save_allocations($newAllocations);
                $output->writeln('<info>✓ Allocations updated</info>');
            }
            else {
                $output->writeln('<comment>Dry run - allocations not updated</comment>');
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

    protected function loadProjectConfig(string $dir): array
    {
        $filename = '.worktree.yml';
        $configPath = "{$dir}/{$filename}";

        if (!file_exists($configPath)) {
            throw new WorktreeException(
                sprintf("%s not found in %s", $filename, $dir)
            );
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

        if (empty($config)) {
            throw new WorktreeException('.worktree.yml is empty');
        }

        return $config;
    }

    protected function parseEnvFile(string $path): array
    {
        $vars = [];
        $content = file_get_contents($path);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                $vars[$matches[1]] = $matches[2];
            }
        }

        return $vars;
    }

    protected function getProjectKey(string $path): string
    {
        // Extract project name by removing -2, -3, etc. suffix
        $dirName = basename($path);
        $projectName = preg_replace('/-\d+$/', '', $dirName);

        return dirname($path) . '/' . $projectName;
    }

    protected function findPortConflicts(array $allocations): array
    {
        $portUsage = [];

        foreach ($allocations['allocations'] as $dirName => $ports) {
            foreach ($ports as $varName => $port) {
                if (!isset($portUsage[$port])) {
                    $portUsage[$port] = [];
                }
                $portUsage[$port][] = $dirName;
            }
        }

        // Filter only conflicts (ports used by multiple directories)
        return array_filter($portUsage, fn($dirs) => count($dirs) > 1);
    }
}
