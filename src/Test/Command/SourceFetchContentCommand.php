<?php

namespace App\Test\Command;

use App\SourceManager;
use App\Test\Fixture\FixtureFetcher;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'test:source:fetch-content',
    description: 'Fetch the content for all the test sources',
)]
#[When('dev')]
class SourceFetchContentCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        FixtureFetcher $fetcher,
    ): int {
        $failed = [];

        foreach ($manager->getSources() as $source) {
            if (!$source->definition instanceof TestDefinition) {
                continue;
            }

            $io->section($source);

            try {
                $fetch = $fetcher->fetch($source);
                $io->success(\sprintf(
                    'Wrote the content of %s to the file %s',
                    $fetch->url,
                    realpath($fetch->filename),
                ));
            } catch (\Exception $e) {
                $failed[] = $source->definition->id;
                $io->error($e->getMessage());
            }
        }

        if ([] !== $failed) {
            $io->warning(\sprintf(
                'Failed: %s. The old files of these sources did not change.',
                implode(', ', $failed),
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
