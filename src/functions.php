<?php

use Osmianski\WorktreeManager\Exception\WorktreeException;

function execute_git_command(string $command): array
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

function ensure_project_dir_is_git_repository(string $path): void
{
    if (!is_dir($path . '/.git') || is_file($path . '/.git')) {
        throw new WorktreeException(sprintf(
            "Not a git repository: %s\nThe current directory must be a git repository to list worktrees.",
            $path
        ));
    }
}
