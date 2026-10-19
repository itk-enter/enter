<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports every source, then dispatches the run's outcome.
 *
 * Test sources are left to test:source:import-all; they exist only in dev
 * and test, and read local copies of the feeds.
 */
#[AsCommand(
    name: 'app:source:import-all',
    description: 'Import all sources',
)]
final class SourceImportAllCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        SourcesImporter $importer,
    ): int {
        $sources = array_filter(
            $manager->getSources(),
            static fn (SourceInterface $source): bool => !$source->definition instanceof TestDefinition
        );

        $event = $importer->import($sources, static function (SourceInterface $source, ImportResult|\Throwable $result) use ($io): void {
            $io->section((string) $source);

            if ($result instanceof \Throwable) {
                $io->error($result->getMessage());

                return;
            }

            $io->success(\sprintf('Upserted %d entities into %s (HTTP %d).', $result->count, $result->brokerUrl, $result->status));
        });

        // A scheduled run must be able to tell that something failed.
        return $event->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
