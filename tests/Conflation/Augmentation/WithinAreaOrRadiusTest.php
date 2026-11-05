<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Augmentation;

use App\Conflation\Augmentation\WithinAreaOrRadius;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Spatial\Distance;
use PHPUnit\Framework\TestCase;

class WithinAreaOrRadiusTest extends TestCase
{
    private const float LONGITUDE = 10.21;
    private const float LATITUDE = 56.15;

    /**
     * A position the given metres east and north of a fixed origin.
     *
     * @return array{float, float}
     */
    private static function at(float $east, float $north): array
    {
        return [
            self::LONGITUDE + $east / (Distance::METRES_PER_DEGREE * cos(deg2rad(self::LATITUDE))),
            self::LATITUDE + $north / Distance::METRES_PER_DEGREE,
        ];
    }

    /**
     * A closed ring around the rectangle between two corners, in metres.
     *
     * @return list<array{float, float}>
     */
    private static function ring(float $west, float $south, float $east, float $north): array
    {
        return [self::at($west, $south), self::at($east, $south), self::at($east, $north), self::at($west, $north), self::at($west, $south)];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function space(string $id, float $east, float $north, array $attributes = []): MergedEntity
    {
        return new MergedEntity($id, ['type' => 'Point', 'coordinates' => self::at($east, $north)], $attributes, [$id]);
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private static function cover(string $id, array $geometry, string $surface): Record
    {
        return new Record($id, 'data-set-c', self::LONGITUDE, self::LATITUDE, 'ModelC', ['surface' => $surface], $geometry);
    }

    /**
     * @param list<MergedEntity> $entities
     * @param list<Record>       $records
     *
     * @return array<string, array<string, list<StatedValue>>>
     */
    private static function augment(array $entities, array $records): array
    {
        return new WithinAreaOrRadius(5.0)->augment($entities, $records, ['ModelC' => ['surface']]);
    }

    public function testAnAreaSuppliesTheEntitiesInsideIt(): void
    {
        // An asphalt area C-5 from 0 to 100 m east, a gravel area C-6 from
        // 100 to 200 m east, both 50 m deep.
        $records = [
            self::cover('C-5', ['type' => 'Polygon', 'coordinates' => [self::ring(0, 0, 100, 50)]], 'asphalt'),
            self::cover('C-6', ['type' => 'Polygon', 'coordinates' => [self::ring(100, 0, 200, 50)]], 'gravel'),
        ];
        $entities = [
            self::space('P-1', 10, 10),
            self::space('P-12', 20, 10, ['surface' => 'gravel']),
            self::space('P-41', 100, 25),
            self::space('P-99', 300, 25),
        ];

        $this->assertEquals([
            'P-1' => ['surface' => [new StatedValue('asphalt', 'C-5', 'data-set-c')]],
            'P-41' => ['surface' => [new StatedValue('asphalt', 'C-5', 'data-set-c'), new StatedValue('gravel', 'C-6', 'data-set-c')]],
        ], self::augment($entities, $records));
    }

    public function testAHoleIsNotPartOfTheArea(): void
    {
        $area = self::cover('C-5', ['type' => 'Polygon', 'coordinates' => [self::ring(0, 0, 100, 100), self::ring(40, 40, 60, 60)]], 'grass');

        $supplied = self::augment([self::space('P-1', 10, 10), self::space('P-2', 50, 50), self::space('P-3', 40, 50)], [$area]);

        // P-3 is on the hole's edge, which still belongs to the area.
        $this->assertSame(['P-1', 'P-3'], array_keys($supplied));
    }

    public function testEveryPartOfAMultiPolygonCounts(): void
    {
        $area = self::cover('C-5', ['type' => 'MultiPolygon', 'coordinates' => [[self::ring(0, 0, 50, 50)], [self::ring(100, 0, 150, 50)]]], 'grass');

        $supplied = self::augment([self::space('P-1', 10, 10), self::space('P-2', 75, 10), self::space('P-3', 125, 10)], [$area]);

        $this->assertSame(['P-1', 'P-3'], array_keys($supplied));
    }

    public function testAPointSuppliesTheEntitiesWithinTheRadius(): void
    {
        [$longitude, $latitude] = self::at(0, 0);
        $point = new Record('C-5', 'data-set-c', $longitude, $latitude, 'ModelC', ['surface' => 'grass']);

        $supplied = self::augment([self::space('P-1', 4, 0), self::space('P-2', 6, 0)], [$point]);

        $this->assertSame(['P-1'], array_keys($supplied));
    }

    public function testAnEntityWithAnAreaIsComparedByItsCentroid(): void
    {
        // A 200 m building, large enough for the centroid to be precise until
        // https://github.com/itk-enter/enter/issues/74 is fixed. Its centroid
        // lies in C-5, though its corner reaches into C-6.
        $building = new MergedEntity('B-1', ['type' => 'Polygon', 'coordinates' => [self::ring(0, 0, 200, 200)]], [], ['B-1']);
        $records = [
            self::cover('C-5', ['type' => 'Polygon', 'coordinates' => [self::ring(50, 50, 150, 150)]], 'asphalt'),
            self::cover('C-6', ['type' => 'Polygon', 'coordinates' => [self::ring(0, 0, 20, 20)]], 'gravel'),
        ];

        $this->assertEquals(['B-1' => ['surface' => [new StatedValue('asphalt', 'C-5', 'data-set-c')]]], self::augment([$building], $records));
    }

    public function testOnlyTheNamedAttributesTheRecordStatesAreSupplied(): void
    {
        $area = ['type' => 'Polygon', 'coordinates' => [self::ring(0, 0, 100, 100)]];
        $records = [
            new Record('C-5', 'data-set-c', self::LONGITUDE, self::LATITUDE, 'ModelC', ['surface' => 'grass', 'colour' => 'green'], $area),
            new Record('C-6', 'data-set-c', self::LONGITUDE, self::LATITUDE, 'ModelC', ['colour' => 'grey'], $area),
            new Record('D-1', 'data-set-d', self::LONGITUDE, self::LATITUDE, 'ModelD', ['surface' => 'sand'], $area),
        ];

        $this->assertEquals(['P-1' => ['surface' => [new StatedValue('grass', 'C-5', 'data-set-c')]]], self::augment([self::space('P-1', 10, 10)], $records));
    }
}
