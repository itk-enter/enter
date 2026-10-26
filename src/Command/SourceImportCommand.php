<?php

namespace App\Command;

use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:source:import',
)]
class SourceImportCommand
{
    public function __invoke(SymfonyStyle $io,
        SourceImporterFactory $factory,
        #[Argument]
        SourceInterface $source): int
    {
        $importer = $factory->getSourceImporter($source);
        $result = $importer->import($source);

        $io->success(sprintf(
            'Upserted %d entities into %s (HTTP %d), had %d rejected and deleted %d stale ones.',
            $result->count - \count($result->rejected),
            $result->brokerUrl,
            $result->status,
            \count($result->rejected),
            $result->deleted
        ));

        return Command::SUCCESS;
    }
}
