<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\FeedOrder;
use App\Import\SourceProcessImporter;
use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports all sources, one after another, each in a PHP process of its own.
 *
 * A failed import, or a process that stops, affects only its own source. The
 * other sources still import. The sources that read the same feed import right
 * after each other, so they get the feed from the cache.
 *
 * This command does not import the test sources. test:source:import-all
 * imports them. The test sources exist only in dev and test, and read local
 * copies of the feeds.
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
        SourceProcessImporter $importer,
    ): int {
        $sources = array_filter(
            $manager->getSources(),
            static fn (SourceInterface $source): bool => !$source->definition instanceof TestDefinition
        );

        $failed = [];
        foreach (FeedOrder::sort($sources) as $source) {
            $io->section((string) $source);

            if (!$importer->import($source, $io->write(...))) {
                $failed[] = $source->definition->id;
            }
        }

        if ([] === $failed) {
            return Command::SUCCESS;
        }

        $io->error(\sprintf('The import of %s failed.', implode(', ', $failed)));

        // A scheduled run must be able to tell that something failed.
        return Command::FAILURE;
    }
}
