PHP CLI tool for managing Git worktree environments with automated port allocation.

## Installation

Install globally via Composer:

```bash
composer global require osmianski/worktree-manager
```

Make sure Composer's global bin directory is in your PATH. Add this to your `~/.bashrc` or `~/.zshrc`:

```bash
export PATH="$HOME/.config/composer/vendor/bin:$PATH"
```

## Usage

Navigate to your Git repository and create a new worktree:

```bash
cd /path/to/your/project
worktree new
```

## For contributors

To contribute to a project clone it locally and create a symlink to it in a directory that is already in your path:

```bash
cd ~
git clone git@github.com:osmianski/worktree-manager.git

cd worktree-manager
composer install

# Link `worktree` command to the dev project 
ln -s $HOME/worktree-manager/bin/worktree $HOME/.local/bin/worktree

# To switch back to the globally installed version:
rm $HOME/.local/bin/worktree
```