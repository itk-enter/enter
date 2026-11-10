<?php

namespace App\Command;

use App\Import\SourcesImporter;
use App\Source\SourceInterface;
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
        SourcesImporter $importer,
        #[Argument]
        SourceInterface $source): int
    {
        $result = $importer->importOne($source);

        $io->success(sprintf(
            'Upserted %d entities into %s (HTTP %d). The broker rejected %d entities. Deleted %d stale entities.',
            $result->count - \count($result->rejected),
            $result->brokerUrl,
            $result->status,
            \count($result->rejected),
            $result->deleted
        ));

        return Command::SUCCESS;
    }
}
