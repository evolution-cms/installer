<?php namespace EvolutionCMS\Installer\Commands;

use EvolutionCMS\Installer\Utilities\SystemInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system-status',
    description: 'Print system status as JSON (for Go TUI adapter).'
)]
class SystemStatusCommand extends Command
{
    protected function configure(): void
    {
        // Keep explicit name/description for compatibility with direct instantiation.
        $this
            ->setName('system-status')
            ->setDescription('Print system status as JSON (for Go TUI adapter).');

        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if ($format !== 'json') {
            // JSON-only contract for the adapter.
            return Command::INVALID;
        }

        $data = SystemInfo::systemStatusJson();
        $output->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return Command::SUCCESS;
    }
}
