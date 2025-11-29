# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP CLI tool for managing Git worktrees with automated port allocation. The tool helps developers create and manage multiple worktrees of the same Git repository, each with its own allocated port numbers for local development.

## Development Commands

For local development, a symlink is created:

```bash
cd ~
git clone git@github.com:osmianski/worktree-manager.git
composer install
ln -s $HOME/worktree-manager/bin/worktree $HOME/.local/bin/worktree
```

Then use it in the target project directory:

```bash
worktree new
```

## Architecture

- **Entry Point:** `bin/worktree` - Symfony Console application bootstrapper
- **Commands** are located in `src/` directory, each command extends `Symfony\Component\Console\Command\Command`

## PHP

- Always add import statements for classes, even for those without namespace. So in code it should read `RuntimeException`, not `\RuntimeException`.
- Always start `elseif`, `else`, `catch` and other language constructs that follow a closing `}` from a new line instead of placing them on the same line as `}`
- Put reusable functions into `src/functions.php`, for function name, use snake_case naming convention, like standard PHP functions.

## Composer

- PHP 8.1+ required
- Uses Symfony Console (^6.0|^7.0) for CLI framework
- Uses Symfony YAML (^6.0|^7.0) for configuration
- PSR-4 autoloading with namespace `Osmianski\WorktreeManager\`

## Markdown

- Before a Markdown list, always add an empty line.
- Right after a heading, always add an empty line.
