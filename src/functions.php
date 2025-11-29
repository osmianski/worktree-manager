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
