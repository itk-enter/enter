<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Pipeline;

use App\Conflation\Augmentation\AbstractAugmentation;
use App\Conflation\Augmentation\WithinAreaOrRadius;
use App\Conflation\Conflation;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\MergeByLocation\AbstractMergeByLocation;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Pipeline\ConflationLimitException;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use App\Tests\Conflation\Fixtures\ToiletConflation;
use App\Tests\Conflation\Spatial\GridIndexTest;
use PHPUnit\Framework\TestCase;

class ConflationPipelineTest extends TestCase
{
    /**
     * Two toilets, each recorded by data sets A and B, a third recorded by A
     * alone, and two ground-cover areas.
     *
     * @return list<Record>
     */
    private static function records(): array
    {
        return [
            self::record('A-1', 'data-set-a', 'ModelA', ['thing' => 't1', 'fee' => 'free']),
            self::record('B-1', 'data-set-b', 'ModelB', ['thing' => 't1', 'fee' => '5 kr']),
            self::record('A-2', 'data-set-a', 'ModelA', ['thing' => 't2', 'surface' => 'gravel']),
            self::record('B-2', 'data-set-b', 'ModelB', ['thing' => 't2']),
            self::record('A-3', 'data-set-a', 'ModelA', ['thing' => 't3']),
            self::record('C-5', 'data-set-c', 'ModelC', ['surface' => 'asphalt']),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function record(string $id, string $sourceId, string $model, array $attributes): Record
    {
        return new Record($id, $sourceId, 10.21, 56.15, $model, $attributes);
    }

    public function testItRunsTheStepsOfAMerge(): void
    {
        $entities = new ConflationPipeline()->run(Conflation::of(ToiletConflation::class), self::records());

        $this->assertSame(['A-1', 'A-2', 'A-3'], array_map(static fn (MergedEntity $entity): string => $entity->id, $entities));

        // Conflicts are settled by the merge's approaches: data set A's fee.
        $this->assertSame(['fee' => 'free', 'surface' => 'asphalt', 'thing' => 't1'], $entities[0]->attributes);
        // Augmenting fills only what the members do not state.
        $this->assertSame(['surface' => 'gravel', 'thing' => 't2'], $entities[1]->attributes);
        $this->assertSame(['surface' => 'asphalt', 'thing' => 't3'], $entities[2]->attributes);

        // Provenance lists the members, and the augmenting records used.
        $this->assertSame(['A-1', 'B-1', 'C-5'], $entities[0]->provenance);
        $this->assertSame(['A-2', 'B-2'], $entities[1]->provenance);
    }

    public function testAugmentingRecordsThatDisagreeAreSettledByTheMergesApproaches(): void
    {
        $records = [
            self::record('A-1', 'data-set-a', 'ModelA', []),
            self::record('C-5', 'data-set-c', 'ModelC', ['surface' => 'asphalt']),
            self::record('D-6', 'data-set-d', 'ModelC', ['surface' => 'gravel']),
        ];

        $entities = new ConflationPipeline()->run(self::conflation(conflictResolution: [new SourceAuthority(['data-set-d'])]), $records);
        $this->assertSame(['surface' => 'gravel'], $entities[0]->attributes);

        $entities = new ConflationPipeline()->run(self::conflation(conflictResolution: [new SourceAuthority(['data-set-x'])]), $records);
        $this->assertSame([], $entities[0]->attributes);
        $this->assertEquals(
            [new StatedValue('asphalt', 'C-5', 'data-set-c'), new StatedValue('gravel', 'D-6', 'data-set-d')],
            $entities[0]->conflicts['surface'],
        );
        $this->assertSame(['A-1', 'C-5', 'D-6'], $entities[0]->provenance);
    }

    public function testTheDefaultMergeByLocationKeepsNeighbouringBenchesApart(): void
    {
        // Three benches 2.5 m apart along a promenade, recorded by data set
        // B 0.8 m off. The radius reaches the neighbours, but each bench
        // stays one merged entity.
        $records = [];
        foreach ([1, 2, 3] as $bench) {
            foreach (['A' => 0.0, 'B' => 0.8] as $set => $offset) {
                $point = GridIndexTest::at($set.'-'.$bench, 'data-set-'.strtolower($set), 2.5 * $bench, $offset);
                $records[] = new Record($point->id, $point->sourceId, $point->longitude, $point->latitude, 'Bench');
            }
        }

        $conflation = new Conflation(
            id: 'merged-benches',
            title: 'Merged benches',
            description: 'Benches merged from several data sets.',
            resultModel: 'Bench',
            defining: ['Bench'],
            mergeByLocation: new OneToOneClosestFirst(radius: 5.0),
        );

        $entities = new ConflationPipeline()->run($conflation, $records);

        $this->assertSame(
            [['A-1', 'B-1'], ['A-2', 'B-2'], ['A-3', 'B-3']],
            array_map(static fn (MergedEntity $entity): array => $entity->provenance, $entities),
        );
    }

    public function testAMergeWithoutApproachesSettlesNoConflict(): void
    {
        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'ResultModel',
            defining: ['ModelA', 'ModelB'],
            mergeByLocation: new GroupedByThing(5.0),
        );

        $entities = new ConflationPipeline()->run($conflation, [
            self::record('A-1', 'data-set-a', 'ModelA', ['thing' => 't1', 'fee' => 'free', 'name' => 'Park toilet']),
            self::record('B-1', 'data-set-b', 'ModelB', ['thing' => 't1', 'fee' => '5 kr']),
        ]);

        // What the members agree on, or only one states, is kept; the fee
        // they disagree on is left out and recorded.
        $this->assertSame(['name' => 'Park toilet', 'thing' => 't1'], $entities[0]->attributes);
        $this->assertEquals(
            [new StatedValue('free', 'A-1', 'data-set-a'), new StatedValue('5 kr', 'B-1', 'data-set-b')],
            $entities[0]->conflicts['fee'],
        );
    }

    public function testAMergeOfOtherModelsRestatesItsRecordsInTheResultModel(): void
    {
        // Data set B publishes "Toilet", which calls the fee "price".
        $merge = new class(5.0) extends AbstractMergeByLocation {
            public function merge(array $records): array
            {
                return array_map(static fn (Cluster $cluster): Cluster => new Cluster(array_map(
                    static fn (Record $record): Record => 'Toilet' === $record->model
                        ? new Record($record->id, $record->sourceId, $record->longitude, $record->latitude, 'PublicToilet', ['thing' => $record->attributes['thing'], 'fee' => $record->attributes['price']])
                        : $record,
                    $cluster->records,
                )), new GroupedByThing($this->radius)->merge($records));
            }
        };

        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet', 'Toilet'],
            mergeByLocation: $merge,
        );

        $entities = new ConflationPipeline()->run($conflation, [
            self::record('A-1', 'data-set-a', 'PublicToilet', ['thing' => 't1', 'fee' => 'free']),
            self::record('B-1', 'data-set-b', 'Toilet', ['thing' => 't1', 'price' => 'free']),
        ]);

        $this->assertSame(['fee' => 'free', 'thing' => 't1'], $entities[0]->attributes);
    }

    public function testMergingByLocationMustNotMoveARecord(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('changed the data set or the location of record A-1');

        $this->runWithGroups(static fn (array $records): array => [
            new Cluster([new Record('A-1', 'data-set-a', 10.3, 56.15, 'ModelA')]),
            new Cluster([$records[1]]),
            new Cluster([$records[2]]),
        ]);
    }

    public function testItRejectsARecordOfAModelTheMergeDoesNotName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConflationPipeline()->run(self::conflation(), [self::record('X-1', 'data-set-x', 'ModelX', [])]);
    }

    public function testMergingByLocationMustPutEveryRecordInOneGroup(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('put record A-3 in 0 groups');

        $this->runWithGroups(static fn (array $records): array => [new Cluster([$records[0]]), new Cluster([$records[1]])]);
    }

    public function testMergingByLocationMustNotPutARecordInTwoGroups(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('put record A-1 in 2 groups');

        $this->runWithGroups(static fn (array $records): array => [
            new Cluster([$records[0], $records[1]]),
            new Cluster([$records[0]]),
            new Cluster([$records[2]]),
        ]);
    }

    public function testMergingByLocationMustNotGroupTwoRecordsOfOneDataSet(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('two records of data set data-set-a');

        $this->runWithGroups(static fn (array $records): array => [new Cluster([$records[0], $records[2]]), new Cluster([$records[1]])]);
    }

    public function testMergingByLocationMustOnlyGroupDefiningRecords(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('grouped record C-5');

        $this->runWithGroups(static fn (array $records): array => [
            new Cluster([$records[0], self::record('C-5', 'data-set-c', 'ModelC', [])]),
            new Cluster([$records[1]]),
            new Cluster([$records[2]]),
        ]);
    }

    public function testAugmentingMustNotOverwriteWhatTheMembersState(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('which its members already state');

        $this->runWithAugmentation(static fn (): array => ['A-2' => ['surface' => [new StatedValue('asphalt', 'C-5', 'data-set-c')]]]);
    }

    public function testAugmentingMustOnlySupplyTheAttributesTheMergeNames(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('which it does not name for that model');

        $this->runWithAugmentation(static fn (): array => ['A-3' => ['colour' => [new StatedValue('green', 'C-5', 'data-set-c')]]]);
    }

    public function testAugmentingMustOnlyUseAugmentingRecords(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('which is not one of its augmenting records');

        $this->runWithAugmentation(static fn (): array => ['A-3' => ['surface' => [new StatedValue('asphalt', 'B-1', 'data-set-b')]]]);
    }

    public function testAugmentingMustNotCreateEntities(): void
    {
        $this->expectException(ConflationLimitException::class);
        $this->expectExceptionMessage('which is not one of its merged entities');

        $this->runWithAugmentation(static fn (): array => ['C-5' => ['surface' => [new StatedValue('asphalt', 'C-5', 'data-set-c')]]]);
    }

    /**
     * @param list<\App\Conflation\ConflictResolution\AbstractConflictResolution> $conflictResolution
     */
    private static function conflation(
        ?AbstractMergeByLocation $mergeByLocation = null,
        array $conflictResolution = [],
        ?AbstractAugmentation $augmentation = null,
    ): Conflation {
        return new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'ResultModel',
            defining: ['ModelA', 'ModelB'],
            mergeByLocation: $mergeByLocation ?? new GroupedByThing(5.0),
            conflictResolution: $conflictResolution ?: [new SourceAuthority(['data-set-a'])],
            augmenting: ['ModelC' => ['surface']],
            augmentation: $augmentation ?? new WithinAreaOrRadius(5.0),
        );
    }

    /**
     * Runs a merge whose merging by location returns the given groups of the
     * defining records.
     *
     * @param \Closure(list<Record>): list<Cluster> $groups
     */
    private function runWithGroups(\Closure $groups): void
    {
        $merge = new class($groups) extends AbstractMergeByLocation {
            /**
             * @param \Closure(list<Record>): list<Cluster> $groups
             */
            public function __construct(
                private readonly \Closure $groups,
            ) {
                parent::__construct(5.0);
            }

            public function merge(array $records): array
            {
                return ($this->groups)($records);
            }
        };

        new ConflationPipeline()->run(self::conflation(mergeByLocation: $merge), [
            self::record('A-1', 'data-set-a', 'ModelA', []),
            self::record('B-1', 'data-set-b', 'ModelB', []),
            self::record('A-3', 'data-set-a', 'ModelA', []),
        ]);
    }

    /**
     * Runs the toilet merge with an augmentation that returns the given
     * values.
     *
     * @param \Closure(): array<string, array<string, list<StatedValue>>> $values
     */
    private function runWithAugmentation(\Closure $values): void
    {
        $augmentation = new class($values) extends AbstractAugmentation {
            /**
             * @param \Closure(): array<string, array<string, list<StatedValue>>> $values
             */
            public function __construct(
                private readonly \Closure $values,
            ) {
                parent::__construct(5.0);
            }

            public function augment(array $entities, array $records, array $attributesByModel): array
            {
                return ($this->values)();
            }
        };

        new ConflationPipeline()->run(self::conflation(augmentation: $augmentation), self::records());
    }
}
