<?php

namespace Osmianski\WorktreeManager\Projects;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class Laravel extends Project
{
    public static function detect(string $path): ?static
    {
        // Check for artisan file
        if (!file_exists($path . '/artisan')) {
            return null;
        }

        // Check for composer.json with laravel/framework
        $composerPath = $path . '/composer.json';
        if (!file_exists($composerPath)) {
            return null;
        }

        $composerJson = json_decode(file_get_contents($composerPath), true);
        if (!isset($composerJson['require']['laravel/framework'])) {
            return null;
        }

        return new self($path);
    }

    public function install(OutputInterface $output): void
    {
        $output->writeln('<info>Running composer install...</info>');
        $result = run('composer install', $this->path);

        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "Composer install failed: %s",
                $result->getErrorOutput()
            ));
        }

        $output->writeln('<info>Running npm install...</info>');
        $result = run('npm install', $this->path);

        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "npm install failed: %s",
                $result->getErrorOutput()
            ));
        }
    }
}
