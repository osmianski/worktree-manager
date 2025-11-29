PHP CLI tool for managing Git worktree environments with automated port allocation.

## For contributors

To contribute to a project clone it locally and create a symlink to it in a directory that is already in your path:

```bash
cd ~
git clone git@github.com:osmianski/worktree-manager.git

cd worktree-manager
composer install

ln -s $HOME/worktree-manager/bin/worktree $HOME/.local/bin/worktree
```