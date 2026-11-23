<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Io;

use App\Conflation\Io\ConflictedEntity;
use App\Conflation\Io\ConflictOverview;
use App\Conflation\Io\MergedDataSet;
use PHPUnit\Framework\TestCase;

class ConflictOverviewTest extends TestCase
{
    /**
     * @param list<string>              $attributes the attributes that data sets a and b disagree on
     * @param array<string, mixed>|null $geometry
     */
    private static function entity(string $id, array $attributes, ?array $geometry = ['type' => 'Point', 'coordinates' => [10.21, 56.15]]): ConflictedEntity
    {
        $conflicts = [];
        foreach ($attributes as $attribute) {
            $conflicts[$attribute] = [
                ['value' => true, 'dataSet' => 'a', 'entity' => 'urn:a:'.$id],
                ['value' => false, 'dataSet' => 'b', 'entity' => 'urn:b:'.$id],
            ];
        }

        return new ConflictedEntity($id, null, $geometry, $conflicts);
    }

    /**
     * @param list<ConflictedEntity> $entities
     */
    private static function overview(array $entities): ConflictOverview
    {
        $dataSet = new MergedDataSet('merged', 'Merged', '', 'PublicToilet', 'https://example.com/context.jsonld', null, ['a', 'b']);

        return new ConflictOverview($dataSet, $entities, ['a' => 'Data set A']);
    }

    public function testItCountsTheEntitiesWithAConflictOnEachAttributeTheMostFirst(): void
    {
        $overview = self::overview([self::entity('1', ['fee']), self::entity('2', ['fee', 'handwashing']), self::entity('3', ['access'])]);

        $this->assertSame(['fee' => 2, 'access' => 1, 'handwashing' => 1], $overview->attributes());
    }

    public function testItCountsTheConflictsThatEachDataSetStatesAValueIn(): void
    {
        $overview = self::overview([self::entity('1', ['fee']), self::entity('2', ['fee', 'handwashing'])]);

        $this->assertSame(['a' => 3, 'b' => 3], $overview->dataSets());
    }

    public function testADataSetWithoutATitleIsShownByItsId(): void
    {
        $overview = self::overview([]);

        $this->assertSame('Data set A', $overview->dataSetTitle('a'));
        $this->assertSame('b', $overview->dataSetTitle('b'));
    }

    /**
     * The map shows only the entities with a geometry. The number of a
     * feature is the place of its entity in the list, so that the popup can
     * link to the values.
     */
    public function testTheMapGetsTheEntitiesWithAGeometry(): void
    {
        $overview = self::overview([self::entity('1', ['fee'], geometry: null), self::entity('2', ['fee', 'handwashing'])]);

        $collection = $overview->featureCollection();

        $this->assertSame('FeatureCollection', $collection['type']);
        $this->assertCount(1, $collection['features']);
        $this->assertSame('2', $collection['features'][0]['id']);
        $this->assertSame(['title' => '2', 'attributes' => ['fee', 'handwashing'], 'number' => 1], $collection['features'][0]['properties']);
    }
}
