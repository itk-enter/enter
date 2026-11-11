<?php

namespace App\Command;

use App\SourceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the sources.
 *
 * With --ids, the command prints only the id of each source, one on each
 * line. A cron job uses this list to import the sources one at a time.
 */
#[AsCommand(
    name: 'app:source:list',
)]
class SourceListCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        #[Option(description: 'Print only the id of each source, one on each line')]
        bool $ids = false,
    ): int {
        $sources = $manager->getSources();

        if ($ids) {
            foreach ($sources as $source) {
                $io->writeln($source->definition->id);
            }

            return Command::SUCCESS;
        }

        $headers = ['ID', 'Title', 'Data type'];
        $rows = [];
        $count = 0;
        foreach ($sources as $source) {
            $definition = $source->definition;
            $rows[] = [$definition->id, $definition->title, $definition->dataType->name];
        }
        $io->table($headers, $rows);

        return Command::SUCCESS;
    }
}
