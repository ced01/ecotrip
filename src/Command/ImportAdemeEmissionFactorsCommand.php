<?php

declare(strict_types=1);

namespace App\Command;

use App\Environmental\AdemeEmissionFactorImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:emissions:import-ademe', description: 'Import a pinned local ADEME Base Carbone CSV transactionally')]
final class ImportAdemeEmissionFactorsCommand extends Command
{
    public function __construct(private readonly AdemeEmissionFactorImporter $importer) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Local ADEME CSV path')
            ->addOption('source-version', null, InputOption::VALUE_REQUIRED, 'ADEME publication version')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Expected lowercase SHA-256')
            ->addOption('effective-from', null, InputOption::VALUE_REQUIRED, 'Verified EcoTrip applicability date (YYYY-MM-DD; default is V23.6 publication date)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $effective = $input->getOption('effective-from');
            $result = $this->importer->import((string)$input->getOption('file'), (string)$input->getOption('source-version'), (string)$input->getOption('sha256'), $effective === null ? null : (string) $effective);
        } catch (\Throwable $error) {
            $output->writeln('<error>Import refused: '.htmlspecialchars($error->getMessage(), ENT_QUOTES).'</error>');
            return Command::INVALID;
        }
        $output->writeln(sprintf('<info>%s ADEME %s (%s): %d factor(s), import #%d.</info>', $result->alreadyImported ? 'Already imported' : 'Imported', $result->sourceVersion, $result->checksum, $result->factorCount, $result->importId));
        return Command::SUCCESS;
    }
}
