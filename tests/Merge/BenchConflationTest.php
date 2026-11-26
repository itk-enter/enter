<?php

declare(strict_types=1);

namespace App\Tests\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationManager;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\BenchConflation;
use App\Source\SourceInterface;
use App\Test\Merge\TestBenchConflation;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BenchConflationTest extends KernelTestCase
{
    /**
     * The source ids of the data sets a merge reads.
     *
     * @return list<string>
     */
    private static function dataSets(string $merge): array
    {
        $container = static::getContainer();
        $conflation = $container->get(ConflationManager::class)->getConflation($merge);

        return array_map(static fn (SourceInterface $source): string => $source->definition->id, $container->get(ConflationFetcher::class)->dataSets($conflation));
    }

    /**
     * One bench as stated by two data sets, at different points and with different descriptions.
     *
     * @param class-string $merge
     */
    private static function resolve(string $merge, string $osm, string $gmPark): MergedEntity
    {
        $records = [
            new Record('O-1', $osm, 10.21, 56.15, 'Bench', ['description' => 'Bænk']),
            new Record('G-1', $gmPark, 10.21002, 56.15001, 'Bench', ['description' => 'Betonbænk']),
        ];

        return new ConflictResolver(Conflation::of($merge)->conflictResolution)->resolve(new Cluster($records));
    }

    public function testItReadsEveryPublishedBenchDataSet(): void
    {
        $this->assertSame([
            'mtm_gmpark-bench',
            'mtm_gmpark-picnic-table',
            'osm-bench',
            'osm-picnic-table',
        ], self::dataSets('merged-benches'));
    }

    public function testItsTestMergeReadsEveryTestBenchDataSet(): void
    {
        $this->assertSame([
            'test:mtm_gmpark-bench',
            'test:mtm_gmpark-picnic-table',
            'test:osm-bench',
            'test:osm-picnic-table',
        ], self::dataSets('test:merged-benches'));
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function benchesAndPicnicTables(): iterable
    {
        yield 'benches' => [BenchConflation::class, 'osm-bench', 'mtm_gmpark-bench'];
        yield 'picnic tables' => [BenchConflation::class, 'osm-picnic-table', 'mtm_gmpark-picnic-table'];
        yield 'test benches' => [TestBenchConflation::class, 'test:osm-bench', 'test:mtm_gmpark-bench'];
        yield 'test picnic tables' => [TestBenchConflation::class, 'test:osm-picnic-table', 'test:mtm_gmpark-picnic-table'];
    }

    /**
     * @param class-string $merge
     */
    #[DataProvider('benchesAndPicnicTables')]
    public function testOsmDecidesTheLocation(string $merge, string $osm, string $gmPark): void
    {
        $this->assertSame(['type' => 'Point', 'coordinates' => [10.21, 56.15]], self::resolve($merge, $osm, $gmPark)->location);
    }

    /**
     * @param class-string $merge
     */
    #[DataProvider('benchesAndPicnicTables')]
    public function testItRecordsAConflictOnAnotherAttribute(string $merge, string $osm, string $gmPark): void
    {
        $entity = self::resolve($merge, $osm, $gmPark);

        $this->assertArrayNotHasKey('description', $entity->attributes);
        $this->assertCount(2, $entity->conflicts['description'] ?? []);
    }
}
