<?php

namespace App\Command;

use App\SourceManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:source:list',
)]
class SourceListCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
    ): int {
        $sources = $manager->getSources();

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
