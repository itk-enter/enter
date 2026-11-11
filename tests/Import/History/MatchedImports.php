<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\SourceImport;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\Collection;

/**
 * The imports that match a criteria, in the kind of collection that the ORM
 * returns from EntityRepository::matching().
 *
 * @extends AbstractLazyCollection<int, SourceImport>
 */
final class MatchedImports extends AbstractLazyCollection
{
    /**
     * @param Collection<int, SourceImport> $matches
     */
    public function __construct(private readonly Collection $matches)
    {
    }

    protected function doInitialize(): void
    {
        $this->collection = $this->matches;
    }
}
