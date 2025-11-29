<?php

/**
 * Integration test for NewCommand
 *
 * This test creates a real git repository with test files and runs
 * the actual worktree new command to verify end-to-end functionality.
 *
 * Run with: php tests/integration/NewCommandTest.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Osmianski\WorktreeManager\NewCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NewCommandTest
{
    private string $fixturesDir;
    private string $testProjectDir;

    public function __construct()
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->testProjectDir = $this->fixturesDir . '/test-project';
    }

    public function run(): void
    {
        echo "Running integration tests...\n\n";

        try {
            $this->cleanup();
            $this->testHappyPath();
            $this->testSequentialWorktrees();
            $this->testMissingConfig();
            $this->testInvalidBranch();
            $this->cleanup();

            echo "\n✓ All tests passed!\n";
        } catch (\Exception $e) {
            echo "\n✗ Test failed: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function testHappyPath(): void
    {
        echo "Test: Happy path - Create first worktree\n";

        $this->setupTestProject();

        $app = new Application();
        $command = new NewCommand();
        $app->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['command' => 'new'],
            ['interactive' => false]
        );

        $output = $commandTester->getDisplay();

        // Verify command succeeded
        $this->assertEquals(0, $commandTester->getStatusCode(), "Command should succeed");

        // Verify output
        $this->assertStringContains($output, 'Next worktree: test-project-2');
        $this->assertStringContains($output, 'Allocating ports');
        $this->assertStringContains($output, 'HTTP_PORT: 8000');
        $this->assertStringContains($output, 'VITE_PORT: 5173');
        $this->assertStringContains($output, 'Worktree created successfully');

        // Verify worktree directory exists
        $worktreePath = $this->fixturesDir . '/test-project-2';
        $this->assertTrue(is_dir($worktreePath), "Worktree directory should exist");

        // Verify .env file exists and contains correct ports
        $envPath = $worktreePath . '/.env';
        $this->assertTrue(file_exists($envPath), ".env file should exist");

        $envContent = file_get_contents($envPath);
        $this->assertStringContains($envContent, 'HTTP_PORT=8000');
        $this->assertStringContains($envContent, 'VITE_PORT=5173');

        // Verify allocations.json was updated
        $allocationsPath = $_SERVER['HOME'] . '/.local/share/worktree-manager/allocations.json';
        $this->assertTrue(file_exists($allocationsPath), "allocations.json should exist");

        $allocations = json_decode(file_get_contents($allocationsPath), true);
        $this->assertArrayHasKey('allocations', $allocations);
        $this->assertArrayHasKey('test-project-2', $allocations['allocations']);
        $this->assertEquals(8000, $allocations['allocations']['test-project-2']['HTTP_PORT']);
        $this->assertEquals(5173, $allocations['allocations']['test-project-2']['VITE_PORT']);

        echo "  ✓ Passed\n\n";
    }

    private function testSequentialWorktrees(): void
    {
        echo "Test: Sequential worktree naming\n";

        // Create second worktree
        chdir($this->testProjectDir);

        $app = new Application();
        $command = new NewCommand();
        $app->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['command' => 'new'],
            ['interactive' => false]
        );

        $output = $commandTester->getDisplay();

        // Should be test-project-3 now
        $this->assertStringContains($output, 'Next worktree: test-project-3');
        $this->assertStringContains($output, 'HTTP_PORT: 8001');
        $this->assertStringContains($output, 'VITE_PORT: 5174');

        // Verify worktree exists
        $worktreePath = $this->fixturesDir . '/test-project-3';
        $this->assertTrue(is_dir($worktreePath), "Second worktree should exist");

        echo "  ✓ Passed\n\n";
    }

    private function testMissingConfig(): void
    {
        echo "Test: Missing worktrees.yml\n";

        // Create project without worktrees.yml
        $emptyProjectDir = $this->fixturesDir . '/empty-project';
        mkdir($emptyProjectDir, 0755, true);
        chdir($emptyProjectDir);

        // Initialize git
        exec('git init');
        exec('git config user.email "test@example.com"');
        exec('git config user.name "Test User"');
        exec('git commit --allow-empty -m "Initial commit"');

        $app = new Application();
        $command = new NewCommand();
        $app->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['command' => 'new'],
            ['interactive' => false]
        );

        $output = $commandTester->getDisplay();

        // Should fail
        $this->assertEquals(1, $commandTester->getStatusCode(), "Command should fail");
        $this->assertStringContains($output, 'worktrees.yml not found');

        echo "  ✓ Passed\n\n";
    }

    private function testInvalidBranch(): void
    {
        echo "Test: Invalid branch name\n";

        chdir($this->testProjectDir);

        $app = new Application();
        $command = new NewCommand();
        $app->add($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['command' => 'new', '--branch' => 'nonexistent'],
            ['interactive' => false]
        );

        $output = $commandTester->getDisplay();

        // Should fail
        $this->assertEquals(1, $commandTester->getStatusCode(), "Command should fail");
        $this->assertStringContains($output, "Branch 'nonexistent' does not exist");

        echo "  ✓ Passed\n\n";
    }

    private function setupTestProject(): void
    {
        // Create fixtures directory
        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }

        // Create test project directory
        mkdir($this->testProjectDir, 0755, true);
        chdir($this->testProjectDir);

        // Initialize git repository
        exec('git init');
        exec('git config user.email "test@example.com"');
        exec('git config user.name "Test User"');

        // Create worktrees.yml
        $worktreesYml = <<<YAML
HTTP_PORT:
  port_range: "8000..8010"
VITE_PORT:
  port_range: "5173..5180"
YAML;
        file_put_contents('worktrees.yml', $worktreesYml);

        // Create a simple docker-compose.yml
        $dockerCompose = <<<YAML
version: '3.8'
services:
  web:
    image: nginx:latest
    ports:
      - "\${HTTP_PORT}:80"
YAML;
        file_put_contents('docker-compose.yml', $dockerCompose);

        // Create bin directory and install.sh
        mkdir('bin', 0755, true);
        $installScript = <<<BASH
#!/bin/bash
echo "Installing dependencies..."
echo "Installation complete!"
BASH;
        file_put_contents('bin/install.sh', $installScript);
        chmod('bin/install.sh', 0755);

        // Create .env.example
        $envExample = <<<ENV
HTTP_PORT=
VITE_PORT=
ENV;
        file_put_contents('.env.example', $envExample);

        // Commit everything
        exec('git add .');
        exec('git commit -m "Initial commit"');
    }

    private function cleanup(): void
    {
        // Remove test fixtures
        if (is_dir($this->fixturesDir)) {
            $this->removeDirectory($this->fixturesDir);
        }

        // Clean up allocations.json
        $allocationsPath = $_SERVER['HOME'] . '/.local/share/worktree-manager/allocations.json';
        if (file_exists($allocationsPath)) {
            $allocations = json_decode(file_get_contents($allocationsPath), true);

            // Remove test project allocations
            if (isset($allocations['allocations'])) {
                foreach (array_keys($allocations['allocations']) as $key) {
                    if (str_starts_with($key, 'test-project')) {
                        unset($allocations['allocations'][$key]);
                    }
                }

                file_put_contents(
                    $allocationsPath,
                    json_encode($allocations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \Exception("{$message}. Expected: {$expected}, Got: {$actual}");
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \Exception($message);
        }
    }

    private function assertStringContains(string $haystack, string $needle): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \Exception("String does not contain '{$needle}'.\nGot: {$haystack}");
        }
    }

    private function assertArrayHasKey(string $key, array $array): void
    {
        if (!array_key_exists($key, $array)) {
            throw new \Exception("Array does not have key '{$key}'");
        }
    }
}

// Run the tests
$test = new NewCommandTest();
$test->run();
