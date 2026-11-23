<?php

namespace App\Command;

use App\Broker\NgsiLdBroker;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:broker:entity:delete',
    description: 'Delete all entities with the given types',
)]
class BrokerEntityDelete
{
    /**
     * @param string[]|null $entityTypes
     */
    public function __invoke(
        SymfonyStyle $io,
        NgsiLdBroker $broker,
        #[Argument(name: 'type')]
        ?array $entityTypes = null,
        #[Option]
        bool $all = false,
    ): int {
        if ($all) {
            $entityTypes = $broker->types();
        } elseif ([] === ($entityTypes ?? [])) {
            throw new RuntimeException('The entity types are missing');
        }

        foreach ($entityTypes ?? [] as $entityType) {
            $ids = $broker->idsOfType($entityType);

            $io->writeln(match (\count($ids)) {
                0 => \sprintf('The broker has no %s entities.', $entityType),
                1 => \sprintf('The command deletes one %s entity …', $entityType),
                default => \sprintf('The command deletes %d %s entities …', \count($ids), $entityType),
            });

            $broker->delete($ids);
        }

        return Command::SUCCESS;
    }
}
