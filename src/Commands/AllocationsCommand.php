<?php

namespace Osmianski\WorktreeManager\Commands;

use Osmianski\WorktreeManager\Exception\WorktreeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AllocationsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('allocations')
            ->setDescription('List port allocations')
            ->addOption(
                'by-port',
                null,
                InputOption::VALUE_NONE,
                'Display as a plain table sorted by port'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $allocations = load_allocations();

            if (empty($allocations['allocations'])) {
                $output->writeln('<comment>No allocations found</comment>');
                return Command::SUCCESS;
            }

            if ($input->getOption('by-port')) {
                $this->displayByPort($allocations['allocations'], $output);
            }
            else {
                $this->displayByWorktree($allocations['allocations'], $output);
            }

            return Command::SUCCESS;
        }
        catch (WorktreeException $e) {
            $output->writeln("\n<error>ERROR</error> {$e->getMessage()}\n");

            if ($e->getDescription()) {
                $output->writeln($e->getDescription());
            }

            return Command::FAILURE;
        }
    }

    protected function displayByWorktree(array $allocations, OutputInterface $output): void
    {
        foreach ($allocations as $worktree => $ports) {
            $output->writeln("<info>{$worktree}</info>");

            foreach ($ports as $var => $port) {
                $output->writeln("  {$var}: {$port}");
            }

            $output->writeln('');
        }
    }

    protected function displayByPort(array $allocations, OutputInterface $output): void
    {
        $rows = [];

        foreach ($allocations as $worktree => $ports) {
            foreach ($ports as $var => $port) {
                $rows[] = [$port, $worktree, $var];
            }
        }

        usort($rows, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $table = new Table($output);
        $table
            ->setHeaders(['Port', 'Worktree', 'Variable'])
            ->setRows($rows);

        $table->render();
    }
}
