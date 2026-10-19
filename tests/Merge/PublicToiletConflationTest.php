<?php

declare(strict_types=1);

namespace App\Tests\Merge;

use App\Conflation\Cluster;
use App\Conflation\Conflation;
use App\Conflation\ConflationFetcher;
use App\Conflation\ConflationManager;
use App\Conflation\ConflictResolver;
use App\Conflation\Record;
use App\Merge\PublicToiletConflation;
use App\Source\SourceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PublicToiletConflationTest extends KernelTestCase
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
     * One toilet as stated by the given data sets, each stating the given
     * opening hours.
     *
     * @param array<string, string> $openingHours by source id
     */
    private static function settle(array $openingHours): mixed
    {
        $records = array_map(
            static fn (string $sourceId, string $hours): Record => new Record($sourceId.'-1', $sourceId, 10.21, 56.15, 'PublicToilet', ['openingHours' => $hours]),
            array_keys($openingHours),
            $openingHours,
        );
        $entity = new ConflictResolver(Conflation::of(PublicToiletConflation::class)->conflictResolution)->resolve(new Cluster($records));

        return $entity->attributes['openingHours'] ?? null;
    }

    public function testItReadsEveryPublishedToiletDataSet(): void
    {
        $this->assertSame([
            'findtoilet-public-toilet',
            'mtm_spatialmaps-toilet-city',
            'mtm_spatialmaps-toilet-other',
            'osm-public-toilet',
        ], self::dataSets('merged-public-toilets'));
    }

    public function testItsTestMergeReadsEveryTestToiletDataSet(): void
    {
        $this->assertSame([
            'test:findtoilet-public-toilet',
            'test:mtm_spatialmaps-toilet-city',
            'test:mtm_spatialmaps-toilet-other',
            'test:osm-public-toilet',
        ], self::dataSets('test:merged-public-toilets'));
    }

    public function testTheMajorityDecidesFirst(): void
    {
        $this->assertSame('06–22', self::settle([
            'findtoilet-public-toilet' => '06–22',
            'mtm_spatialmaps-toilet-city' => '08–20',
            'osm-public-toilet' => '06–22',
        ]));
    }

    public function testWithoutAMajorityTheCityToiletsDecide(): void
    {
        $this->assertSame('08–20', self::settle([
            'mtm_spatialmaps-toilet-city' => '08–20',
            'mtm_spatialmaps-toilet-other' => '07–21',
            'osm-public-toilet' => '06–22',
        ]));
    }

    public function testWithoutTheCityToiletsTheOtherToiletsDecide(): void
    {
        $this->assertSame('07–21', self::settle([
            'mtm_spatialmaps-toilet-other' => '07–21',
            'osm-public-toilet' => '06–22',
        ]));
    }

    public function testWithoutEitherTheConflictIsRecorded(): void
    {
        $this->assertNull(self::settle([
            'findtoilet-public-toilet' => '07–21',
            'osm-public-toilet' => '06–22',
        ]));
    }
}
