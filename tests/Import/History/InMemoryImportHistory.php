<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\ImportHistory;
use App\Import\History\SourceImport;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Makes an ImportHistory over imports in memory, for a test case.
 *
 * A stub of the repository matches the criteria of ImportHistory against the
 * imports with the matcher of Doctrine Collections. The matcher filters, sorts
 * and limits in memory as the SQL of the ORM does in the database.
 */
trait InMemoryImportHistory
{
    private function importHistory(SourceImport ...$imports): ImportHistory
    {
        $all = new ArrayCollection(array_values($imports));

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('matching')->willReturnCallback(
            static fn (Criteria $criteria): MatchedImports => new MatchedImports($all->matching($criteria)),
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ImportHistory($entityManager);
    }

    /**
     * Makes an import that finished at a time in UTC. Without a payload hash,
     * the import failed.
     *
     * @param ?string $payloadHash a short name of the hash, for example "a"
     */
    private static function import(string $sourceId, string $finishedAt, ?string $payloadHash): SourceImport
    {
        $finished = new \DateTimeImmutable($finishedAt.' UTC');
        $import = new SourceImport($sourceId, $finished->modify('-1 minute'));

        if (null === $payloadHash) {
            $import->fail($finished, 'Feed unreachable');
        } else {
            $import->succeed($finished, str_pad($payloadHash, 64, '0'), 1, 0, 0);
        }

        return $import;
    }
}
