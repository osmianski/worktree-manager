Need to maintain multiple working branches simultaneously - whether for long-term parallel development or running AI agents in separate worktrees? Setting up each environment is tedious.

This tool automates it: create worktrees with fully working environments in seconds, automatically allocate ports, install dependencies, create Docker containers and migrate data.

## Installation

**Requirements:** PHP 8.1 or higher

1. Install globally via Composer:

    ```bash
    composer global require osmianski/worktree-manager
    ```

2. Make sure Composer's global `bin` directory is in your PATH. The location varies by system:

    ```bash
    # Most Linux systems
    export PATH="$HOME/.config/composer/vendor/bin:$PATH"

    # Or on some systems
    export PATH="$HOME/.composer/vendor/bin:$PATH"
    ```

    Add the appropriate line to your `~/.bashrc` or `~/.zshrc`.

3. Verify the installation:

    ```bash
    worktree --version
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