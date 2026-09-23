<?php

declare(strict_types=1);

namespace App\Command;

use App\Place\FilBleuGtfsImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:places:import-filbleu', description: 'Import a pinned local Fil Bleu GTFS snapshot transactionally')]
final class ImportFilBleuPlacesCommand extends Command
{
    public function __construct(private readonly FilBleuGtfsImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the local GTFS ZIP')
            ->addOption('feed-version', null, InputOption::VALUE_REQUIRED, 'Expected feed_version')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Expected lowercase SHA-256');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->importer->import(
                (string) $input->getOption('file'),
                (string) $input->getOption('feed-version'),
                (string) $input->getOption('sha256'),
            );
        } catch (\InvalidArgumentException $error) {
            $output->writeln('<error>Import refused: '.htmlspecialchars($error->getMessage(), ENT_QUOTES).'</error>');
            return Command::INVALID;
        }
        $output->writeln(sprintf(
            '<info>Imported feed %s (%s): %d commercial stations, %d stop references; import #%d.</info>',
            $result->feedVersion, $result->checksum, $result->stationCount, $result->referenceCount, $result->importId,
        ));
        return Command::SUCCESS;
    }
}
