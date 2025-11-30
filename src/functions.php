<?php

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Osmianski\WorktreeManager\Projects\Project;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

function load_worktree_config(?string $dir = null): array
{
    $dir = $dir ?? getcwd();
    $filename = '.worktree.yml';
    $configPath = $dir . '/' . $filename;

    if (!file_exists($configPath)) {
        throw new WorktreeException(
            sprintf("%s not found in %s", $filename, $dir),
            sprintf(
                "Please create a %s file with port configuration.\n\nExample:\nenvironment:\n  HTTP_PORT:\n    port_range: \"8000..\"\n  VITE_PORT:\n    port_range: \"5173..\"",
                $filename,
            )
        );
    }

    try {
        $config = Yaml::parseFile($configPath);
        return $config ?? [];
    }
    catch (Exception $e) {
        throw new WorktreeException(sprintf(
            "Invalid YAML syntax in .worktree.yml: %s",
            $e->getMessage()
        ));
    }
}

function get_project_types(): array
{
    return [
        \Osmianski\WorktreeManager\Projects\Laravel::class,
        \Osmianski\WorktreeManager\Projects\Monorepo::class,
    ];
}

function detect_project(?string $path = null, array $except = []): ?Project
{
    $path = $path ?? getcwd();

    foreach (get_project_types() as $projectType) {
        if (in_array($projectType, $except)) {
            continue;
        }

        if ($project = $projectType::detect($path)) {
            return $project;
        }
    }

    return null;
}

function generate_env_file(string $path, array $ports): void
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

function parse_port_range(mixed $value, string $varName = null): array
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

function parse_env_file(string $path): array
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

function run_hook(string $hookName, array $config, OutputInterface $output): bool
{
    $workingDir = getcwd();

    // 1. Check config for hooks/{hookName}
    if (isset($config['hooks'][$hookName])) {
        $output->writeln('');
        $output->writeln("<info>Running {$hookName} hook from .worktree.yml...</info>");
        execute_hook($config['hooks'][$hookName], $workingDir, $output);
        return true;
    }

    // 2. Check .worktree/hooks/{hookName} file
    $hookPath = $workingDir . '/.worktree/hooks/' . $hookName;

    if (file_exists($hookPath)) {
        $output->writeln('');
        $output->writeln("<info>Running {$hookName} hook from .worktree/hooks/{$hookName}...</info>");
        execute_hook($hookPath, $workingDir, $output);
        return true;
    }

    return false;
}

function execute_hook(mixed $hookValue, string $workingDir, OutputInterface $output): void
{
    $commands = is_array($hookValue) ? $hookValue : [$hookValue];

    foreach ($commands as $command) {
        // Check if it's a file path
        $filePath = $workingDir . '/' . $command;

        if (file_exists($filePath)) {
            // Execute as file (must be executable)
            $output->writeln("  Executing: {$command}");
            $process = run($filePath, $workingDir);
        }
        else {
            // Execute as shell command
            $output->writeln("  Running: {$command}");
            $process = run($command, $workingDir);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Command failed with exit code %d: %s\n%s",
                $process->getExitCode(),
                $command,
                $process->getErrorOutput()
            ));
        }
    }
}

function run_install($application, OutputInterface $output, ?string $worktreePath = null): void
{
    $currentDir = $worktreePath ? getcwd() : null;

    if ($worktreePath) {
        chdir($worktreePath);
    }

    try {
        $exitCode = $application->find('install')->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);

        if ($exitCode !== 0) {
            $errorPath = $worktreePath ?? getcwd();
            throw new WorktreeException(
                'Install command failed',
                "You may need to run it manually:\n  cd {$errorPath}\n  worktree install"
            );
        }
    }
    finally {
        if ($currentDir) {
            chdir($currentDir);
        }
    }
}

function run_migrations($application, OutputInterface $output, ?string $worktreePath = null): void
{
    $currentDir = $worktreePath ? getcwd() : null;

    if ($worktreePath) {
        chdir($worktreePath);
    }

    try {
        $exitCode = $application->find('migrate')->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);

        if ($exitCode !== 0) {
            $errorPath = $worktreePath ?? getcwd();
            throw new WorktreeException(
                'Migrate command failed',
                "You may need to run it manually:\n  cd {$errorPath}\n  worktree migrate"
            );
        }
    }
    finally {
        if ($currentDir) {
            chdir($currentDir);
        }
    }
}

function run_up($application, OutputInterface $output, ?string $worktreePath = null): void
{
    $currentDir = $worktreePath ? getcwd() : null;

    if ($worktreePath) {
        chdir($worktreePath);
    }

    try {
        $exitCode = $application->find('up')->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);

        if ($exitCode !== 0) {
            $errorPath = $worktreePath ?? getcwd();
            throw new WorktreeException(
                'Up command failed',
                "You may need to run it manually:\n  cd {$errorPath}\n  worktree up"
            );
        }
    }
    finally {
        if ($currentDir) {
            chdir($currentDir);
        }
    }
}

function run_down($application, OutputInterface $output, ?string $worktreePath = null): void
{
    $currentDir = $worktreePath ? getcwd() : null;

    if ($worktreePath) {
        chdir($worktreePath);
    }

    try {
        $exitCode = $application->find('down')->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);

        if ($exitCode !== 0) {
            $errorPath = $worktreePath ?? getcwd();
            throw new WorktreeException(
                'Down command failed',
                "You may need to run it manually:\n  cd {$errorPath}\n  worktree down"
            );
        }
    }
    finally {
        if ($currentDir) {
            chdir($currentDir);
        }
    }
}
