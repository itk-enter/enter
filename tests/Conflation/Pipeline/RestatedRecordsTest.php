<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Pipeline;

use App\Conflation\Conflation;
use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\MergeByLocation\AbstractMergeByLocation;
use App\Conflation\Pipeline\ConflationLimitException;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Conflation\Spatial\Distance;
use PHPUnit\Framework\TestCase;

/**
 * Tests a merge by location that restates one record as several: a site with
 * bays restated as one record per bay.
 */
class RestatedRecordsTest extends TestCase
{
    private const float LONGITUDE = 10.21;
    private const float LATITUDE = 56.15;

    /**
     * A site S-1 of data set "sites" with two bays, and a mapped bay B-1 of
     * data set "bays" at the same place.
     *
     * @return list<Record>
     */
    private static function records(): array
    {
        return [
            new Record('B-1', 'bays', self::LONGITUDE, self::LATITUDE, 'Bay', ['fee' => 'free']),
            new Record('S-1', 'sites', self::LONGITUDE, self::LATITUDE, 'Site', ['fee' => '5 kr', 'bays' => 2]),
        ];
    }

    /**
     * A bay restated from site S-1.
     *
     * @param array<string, mixed> $changes
     */
    private static function bay(int $number, array $changes = []): Record
    {
        $values = [
            'id' => 'S-1-bay-'.$number,
            'sourceId' => 'sites',
            'longitude' => self::LONGITUDE,
            'latitude' => self::LATITUDE,
            'model' => 'Bay',
            'attributes' => ['fee' => '5 kr'],
            'geometry' => null,
            'restatedFrom' => 'S-1',
            ...$changes,
        ];

        return new Record(...$values);
    }

    /**
     * Runs a merge whose merging by location returns the given groups.
     *
     * @param \Closure(array<string, Record>): list<Cluster> $groups             receives the defining records by id
     * @param list<AbstractConflictResolution>               $conflictResolution
     *
     * @return list<MergedEntity>
     */
    private static function runWithGroups(\Closure $groups, ?array $conflictResolution = null): array
    {
        $merge = new class($groups) extends AbstractMergeByLocation {
            /**
             * @param \Closure(array<string, Record>): list<Cluster> $groups
             */
            public function __construct(
                private readonly \Closure $groups,
            ) {
                parent::__construct(5.0);
            }

            public function merge(array $records): array
            {
                return ($this->groups)(array_column($records, null, 'id'));
            }
        };

        $conflation = new Conflation(
            id: 'merged-bays',
            title: 'Merged bays',
            description: 'Bays merged from bays and sites.',
            resultModel: 'Bay',
            defining: ['Bay', 'Site'],
            mergeByLocation: $merge,
            conflictResolution: $conflictResolution ?? [new SourceAuthority(['bays'])],
        );

        return new ConflationPipeline()->run($conflation, self::records());
    }

    public function testASiteRestatedAsBaysGivesOneEntityPerBay(): void
    {
        $entities = self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2)]),
        ]);

        $this->assertSame(['B-1', 'S-1-bay-2'], array_map(static fn (MergedEntity $entity): string => $entity->id, $entities));
        $this->assertSame(['fee' => 'free'], $entities[0]->attributes);
        $this->assertSame(['fee' => '5 kr'], $entities[1]->attributes);
    }

    public function testProvenanceNamesTheRecordABayIsRestatedFrom(): void
    {
        $entities = self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2)]),
        ]);

        $this->assertSame(['B-1', 'S-1'], $entities[0]->provenance);
        $this->assertSame(['S-1'], $entities[1]->provenance);
    }

    public function testARecordedConflictNamesTheRecordABayIsRestatedFrom(): void
    {
        $entities = self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2)]),
        ], conflictResolution: []);

        $this->assertSame(['B-1', 'S-1'], array_map(static fn (StatedValue $value): string => $value->recordId, $entities[0]->conflicts['fee']));
    }

    public function testARestatedBayMayLieWithinTheRadiusOfItsSite(): void
    {
        $entities = self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2, ['latitude' => self::LATITUDE + 4 / Distance::METRES_PER_DEGREE])]),
        ]);

        $this->assertCount(2, $entities);
    }

    public function testARestatedBayMustNotLieBeyondTheRadiusOfItsSite(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('put record S-1-bay-2 further than 5 m from S-1');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2, ['latitude' => self::LATITUDE + 6 / Distance::METRES_PER_DEGREE])]),
        ]);
    }

    public function testARestatedBayMustKeepTheDataSetOfItsSite(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('changed the data set of record S-1-bay-2');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2, ['sourceId' => 'other'])]),
        ]);
    }

    public function testARestatedBayMustBeInTheResultModel(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('restated record S-1-bay-2 in model Site');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(2, ['model' => 'Site'])]),
        ]);
    }

    public function testARestatedBayMustHaveAnIdOfItsOwn(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('restated record S-1 under the id S-1-bay-1, which another record already has');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([self::bay(1)]),
        ]);
    }

    public function testARestatedBayMustNotTakeTheIdOfAnInputRecord(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('under the id B-1');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([self::bay(1, ['id' => 'B-1'])]),
        ]);
    }

    public function testARestatedBayMustComeFromADefiningRecord(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('grouped record X-1-bay-1, restated from X-1');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1']]),
            new Cluster([self::bay(1)]),
            new Cluster([self::bay(1, ['id' => 'X-1-bay-1', 'restatedFrom' => 'X-1'])]),
        ]);
    }

    public function testASiteMustNotBeGroupedAsItselfAndRestated(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('grouped record S-1 both as itself and restated');

        self::runWithGroups(static fn (array $records): array => [
            new Cluster([$records['B-1'], self::bay(1)]),
            new Cluster([$records['S-1']]),
        ]);
    }

    public function testASiteMustNotBeLeftOut(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('put record S-1 in 0 groups');

        self::runWithGroups(static fn (array $records): array => [new Cluster([$records['B-1']])]);
    }
}
