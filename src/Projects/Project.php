<?php

namespace Osmianski\WorktreeManager\Projects;

use Symfony\Component\Console\Output\OutputInterface;

abstract class Project
{
    abstract public static function detect(string $path): ?static;

    public function __construct(protected string $path)
    {
    }

    public function install(OutputInterface $output): void
    {
        // Default no-op
    }

    public function migrate(OutputInterface $output): void
    {
        // Default no-op
    }
}
