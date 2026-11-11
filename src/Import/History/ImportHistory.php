<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the import history.
 */
final readonly class ImportHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Tells if the data set of a source got new data since a time.
     *
     * The data set got new data if the last successful import since the time
     * has another payload hash than the last successful import before the
     * time. If the source has no successful import before the time, a
     * successful import since the time is new data. A failed import is never
     * new data, because it does not change the data set.
     */
    public function hasNewDataSince(string $sourceId, \DateTimeImmutable $since): bool
    {
        $last = $this->lastSuccessfulImport($sourceId, Criteria::expr()->gte('finishedAt', $since));
        if (null === $last) {
            return false;
        }

        $before = $this->lastSuccessfulImport($sourceId, Criteria::expr()->lt('finishedAt', $since));

        return $last->getPayloadHash() !== $before?->getPayloadHash();
    }

    /**
     * Finds the last successful import of a source that finished in a period.
     *
     * @param Comparison $finishedAt the condition on the end time of the import
     */
    private function lastSuccessfulImport(string $sourceId, Comparison $finishedAt): ?SourceImport
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('sourceId', $sourceId))
            ->andWhere(Criteria::expr()->eq('status', ImportStatus::Succeeded))
            ->andWhere($finishedAt)
            ->orderBy(['finishedAt' => \SortDirection::Descending])
            ->setMaxResults(1);

        $import = $this->entityManager->getRepository(SourceImport::class)->matching($criteria)->first();

        return false === $import ? null : $import;
    }
}
