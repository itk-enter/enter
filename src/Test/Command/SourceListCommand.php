<?php

namespace App\Test\Command;

use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'test:source:list',
    description: 'List all test sources',
)]
#[When('dev')]
class SourceListCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
    ): int {
        $sources = array_filter($manager->getSources(), static fn (SourceInterface $source) => $source->definition  instanceof TestDefinition);

        $io->writeln(sprintf('#sources: %d', \count($sources)));
        foreach ($sources as $source) {
            $io->writeln((string) $source);
        }

        return Command::SUCCESS;
    }
}
