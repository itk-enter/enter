<?php

namespace App\Test\Command;

use App\SourceManager;
use App\Test\Fixture\FixtureFetcher;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'test:source:fetch-content',
    description: 'Fetch content for all test sources',
)]
#[When('dev')]
class SourceFetchContentCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        FixtureFetcher $fetcher,
        #[Option(description: 'Fetch every feed, also those fetched within the last hour')]
        bool $fresh = false,
    ): int {
        $failed = [];

        foreach ($manager->getSources() as $source) {
            if (!$source->definition instanceof TestDefinition) {
                continue;
            }

            $io->section($source);

            try {
                $fetch = $fetcher->fetch($source, $fresh);
                $io->success(\sprintf(
                    'Content %s written to file %s',
                    $fetch->fromCache ? 'fetched earlier' : 'fetched from '.$fetch->url,
                    realpath($fetch->filename),
                ));
            } catch (\Exception $e) {
                $failed[] = $source->definition->id;
                $io->error($e->getMessage());
            }
        }

        if ([] !== $failed) {
            $io->warning(\sprintf(
                "Failed: %s.\nRun the command again within the hour to fetch only these; the rest are kept.",
                implode(', ', $failed),
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
