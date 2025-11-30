<?php

namespace Osmianski\WorktreeManager;

use Symfony\Component\Console\Command\ListCommand;

class HelpListCommand extends ListCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setName('help:list');
    }
}
