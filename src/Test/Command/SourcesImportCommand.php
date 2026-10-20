<?php

namespace App\Test\Command;

use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'test:source:import-all',
    description: 'Import all test sources',
)]
#[When('dev')]
class SourcesImportCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        SourcesImporter $importer,
    ): int {
        $sources = array_filter(
            $manager->getSources(),
            static fn (SourceInterface $source): bool => $source->definition instanceof TestDefinition
        );

        // The run's outcome is dispatched like a real import's, so whatever
        // follows a full import can be tried against the test sources.
        $importer->import($sources, static function (SourceInterface $source, ImportResult|\Throwable $result) use ($io): void {
            $io->section((string) $source);

            if ($result instanceof \Throwable) {
                $io->error($result->getMessage());

                return;
            }

            $io->success(\sprintf('Upserted %d entities into %s (HTTP %d).', $result->count, $result->brokerUrl, $result->status));
        });

        return Command::SUCCESS;
    }
}
