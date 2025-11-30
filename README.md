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

Updating to the latest version:

```bash
composer global update osmianski/worktree-manager
```

## Usage

Navigate to your Git repository and create a new worktree:

```bash
cd /path/to/your/project
worktree new
```

## For contributors

To contribute to a project clone it locally:

```bash
cd ~
git clone git@github.com:osmianski/worktree-manager.git

cd worktree-manager
composer install
```

To run the dev version in a terminal session, run the following command:

```bash
export PATH="$HOME/worktree-manager/bin:$PATH"
```

Now if you run `worktree`, it should show the branch name instead of the version number:

```
Worktree Manager dev-main
```

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.