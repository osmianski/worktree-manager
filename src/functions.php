<?php

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

function run(string $command, ?string $workingDir = null): Process
{
    $process = Process::fromShellCommandline($command, $workingDir);
    $process->run();

    return $process;
}

function run_script(OutputInterface $output, string $command, ?string $workingDir = null): Process
{
    $process = new Process(explode(' ', $command), $workingDir);

    if (Process::isTtySupported()) {
        try {
            $process->setTty(true);
        }
        catch (RuntimeException $e) {
            // TTY not available, continue without it
        }
    }

    $process->run(function ($type, $line) use ($output) {
        $output->write('  ' . $line);
    });

    return $process;
}

function ensure_project_dir_is_git_repository(string $path): void
{
    if (!is_dir($path . '/.git') || is_file($path . '/.git')) {
        throw new WorktreeException(sprintf(
            "Not a git repository: %s\nThe current directory must be a git repository to list worktrees.",
            $path
        ));
    }
}

function get_home_directory(): string
{
    $home = $_SERVER['HOME'] ?? getenv('HOME');

    if (!$home) {
        throw new WorktreeException('Could not determine home directory');
    }

    return $home;
}

function get_allocations_path(): string
{
    return get_home_directory() . '/.local/share/worktree-manager/allocations.json';
}

function get_config_path(): string
{
    return get_home_directory() . '/.config/worktree-manager/config.yml';
}

function load_global_config(): array
{
    $configPath = get_config_path();

    if (!file_exists($configPath)) {
        return ['reserved_ports' => [], 'roots' => []];
    }

    try {
        $config = Symfony\Component\Yaml\Yaml::parseFile($configPath);
        return $config ?? ['reserved_ports' => [], 'roots' => []];
    }
    catch (Exception $e) {
        throw new WorktreeException(sprintf(
            "Invalid YAML syntax in config.yml: %s",
            $e->getMessage()
        ));
    }
}

function save_global_config(array $config): void
{
    $configPath = get_config_path();
    $directory = dirname($configPath);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $yaml = Symfony\Component\Yaml\Yaml::dump($config, 2, 2);
    file_put_contents($configPath, $yaml);
}

function expand_path(string $path): string
{
    // Expand ~ to home directory
    if (str_starts_with($path, '~/') || $path === '~') {
        $home = get_home_directory();
        $path = $home . substr($path, 1);
    }

    return $path;
}

function find_directories_with_worktree_config(string $rootPath): array
{
    $directories = [];

    if (!is_dir($rootPath)) {
        return $directories;
    }

    // Check direct children only
    $entries = scandir($rootPath);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $fullPath = $rootPath . '/' . $entry;

        if (!is_dir($fullPath)) {
            continue;
        }

        // Check if this directory has .worktree.yml
        if (file_exists($fullPath . '/.worktree.yml')) {
            $directories[] = $fullPath;
        }
    }

    return $directories;
}

function load_allocations(): array
{
    $allocationsPath = get_allocations_path();

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

function save_allocations(array $allocations): void
{
    $allocationsPath = get_allocations_path();
    $directory = dirname($allocationsPath);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $json = json_encode($allocations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // Atomic write: write to temp file, then rename
    $tempPath = $allocationsPath . '.tmp';
    file_put_contents($tempPath, $json);
    rename($tempPath, $allocationsPath);
}
