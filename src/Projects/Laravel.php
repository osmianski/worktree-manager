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

        return new static($path);
    }

    public function install(OutputInterface $output): void
    {
        $output->writeln('<info>Running composer install...</info>');
        $result = run('composer install', $this->path);

        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf("Composer install failed: %s", $result->getErrorOutput()));
        }

        $envPath = $this->path . '/.env';
        if (!file_exists($envPath)) {
            $output->writeln('<info>Setting up .env file...</info>');
            $result = run('cp .env.example .env', $this->path);

            if (!$result->isSuccessful()) {
                throw new \RuntimeException(sprintf("Failed to copy .env.example: %s", $result->getErrorOutput()));
            }

            $output->writeln('<info>Generating application key...</info>');
            $result = run('php artisan key:generate', $this->path);

            if (!$result->isSuccessful()) {
                throw new \RuntimeException(sprintf("Failed to generate application key: %s", $result->getErrorOutput()));
            }
        }

        $output->writeln('<info>Running npm install...</info>');
        $result = run('npm install', $this->path);

        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf("npm install failed: %s", $result->getErrorOutput()));
        }
    }
}
