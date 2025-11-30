<?php

namespace Osmianski\WorktreeManager\Projects;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Output\OutputInterface;

class Monorepo extends Project
{
    /**
     * @param string $path
     * @param Project[] $subprojects
     */
    public function __construct(
        protected string $path,
        protected array $subprojects = []
    ) {
        parent::__construct($path);
    }

    public static function detect(string $path): ?static
    {
        if (!is_dir($path)) {
            return null;
        }

        $subprojects = [];
        $entries = scandir($path);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $subdirPath = $path . '/' . $entry;

            if (!is_dir($subdirPath)) {
                continue;
            }

            // Try detecting subproject, excluding Monorepo to prevent nesting
            $project = detect_project($subdirPath, [static::class]);

            if ($project !== null) {
                $subprojects[] = $project;
            }
        }

        return !empty($subprojects) ? new static($path, $subprojects) : null;
    }

    public function install(OutputInterface $output): void
    {
        if (file_exists($this->path . '/package.json')) {
            $output->writeln('<info>Running npm install...</info>');
            $result = run('npm install', $this->path);

            if (!$result->isSuccessful()) {
                throw new WorktreeException('npm install failed', $result->getErrorOutput());
            }
        }

        foreach ($this->subprojects as $subproject) {
            $projectName = basename($subproject->path);
            $output->writeln('');
            $output->writeln("<info>Installing {$projectName}...</info>");

            $subproject->install($output);

            $output->writeln("<info>✓ {$projectName} installed</info>");
        }
    }

    public function migrate(OutputInterface $output): void
    {
        foreach ($this->subprojects as $subproject) {
            $projectName = basename($subproject->path);
            $output->writeln('');
            $output->writeln("<info>Migrating {$projectName}...</info>");

            $subproject->migrate($output);

            $output->writeln("<info>✓ {$projectName} migrated</info>");
        }
    }
}
