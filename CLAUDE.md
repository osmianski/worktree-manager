# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP CLI tool for managing Git worktrees with automated port allocation. The tool helps developers create and manage multiple worktrees of the same Git repository, each with its own allocated port numbers for local development.

## Development Commands

**Run the CLI tool:**
```bash
php bin/worktree <command>
```

**Install dependencies:**
```bash
composer install
```

**Main command:**
```bash
php bin/worktree new
```

## Architecture

**Entry Point:** `bin/worktree`
- Symfony Console application bootstrapper
- Application name: "Worktree Manager"
- Version: 0.1.0

**Command Structure:**
- Commands are located in `src/` directory
- Each command extends `Symfony\Component\Console\Command\Command`
- Namespace: `Osmianski\WorktreeManager`
- PSR-4 autoloading configured in composer.json

**Currently Implemented:**
- `NewCommand` (`new` command): Creates a new worktree with allocated ports
  - Located at: `src/NewCommand.php`
  - Status: Skeleton implementation (TODO: port allocation and worktree creation logic)

## Key Implementation Details

- PHP 8.1+ required
- Uses Symfony Console (^6.0|^7.0) for CLI framework
- Uses Symfony YAML (^6.0|^7.0) for configuration
- PSR-4 autoloading with namespace `Osmianski\WorktreeManager\`
