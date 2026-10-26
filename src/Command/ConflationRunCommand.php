<?php

declare(strict_types=1);

namespace App\Command;

use App\Conflation\ConflationManager;
use App\Conflation\ConflationRunner;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\StatedValue;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:conflation:run',
    description: 'Runs a merge, or every merge, and publishes the merged data set',
)]
class ConflationRunCommand
{
    public function __invoke(
        SymfonyStyle $io,
        ConflationManager $manager,
        ConflationRunner $runner,
        #[Argument(description: 'The id of the merge to run; left out, every merge runs')]
        ?string $merge = null,
        #[Option(description: 'Report what the merge produces without publishing it')]
        bool $dryRun = false,
    ): int {
        $conflations = null === $merge ? $manager->getConflations() : [$manager->getConflation($merge)];
        if ([] === $conflations) {
            $io->warning('No merge is declared.');

            return Command::SUCCESS;
        }

        foreach ($conflations as $conflation) {
            $io->section($conflation->id);
            $run = $runner->run($conflation, !$dryRun);

            $io->writeln(\sprintf('Read %d records from %s.', $run->records, implode(', ', $run->dataSet->inputs)));
            $io->writeln(\sprintf('Merged them into %d entities, %d of them with conflicts.', \count($run->entities), \count($run->withConflicts())));

            if ($io->isVerbose()) {
                foreach ($run->withConflicts() as $entity) {
                    $io->writeln(\sprintf('  %s: %s', $entity->id, $this->conflicts($entity)));
                }
            }

            if (null === $run->upserted) {
                $io->note('Dry run: nothing was published.');
            } else {
                $io->success(\sprintf('Published %d entities as %s and deleted %d stale ones.', $run->upserted, $run->dataSet->id, $run->deleted));
            }
        }

        return Command::SUCCESS;
    }

    private function conflicts(MergedEntity $entity): string
    {
        $conflicts = [];
        foreach ($entity->conflicts as $attribute => $values) {
            $stated = array_map(static fn (StatedValue $value): string => \sprintf('%s (%s)', json_encode($value->value, \JSON_UNESCAPED_UNICODE), $value->sourceId), $values);
            $conflicts[] = \sprintf('%s = %s', $attribute, implode(' / ', $stated));
        }

        return implode('; ', $conflicts);
    }
}
