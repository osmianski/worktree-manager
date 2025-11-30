<?php

namespace Osmianski\WorktreeManager;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);

        $this->setDefaultCommand('help:list');
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        $this->setStyles($output->getFormatter());

        if ($output instanceof ConsoleOutputInterface) {
            $this->setStyles($output->getErrorOutput()->getFormatter());
        }
    }

    protected function setStyles(OutputFormatterInterface $formatter): void
    {
        // See https://www.ditig.com/256-colors-cheat-sheet for supported colors
        $formatter->setStyle('error', new OutputFormatterStyle('white', '#5F0000'));
    }
}
