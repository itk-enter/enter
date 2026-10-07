<?php

declare(strict_types=1);

namespace App\Tests\Source;

use App\Source\DataType;
use App\Source\Definition;
use PHPUnit\Framework\TestCase;

class DefinitionTest extends TestCase
{
    public function testASourceWithoutADataSetIsADataSetOfItsOwn(): void
    {
        $this->assertSame(['id' => 'source', 'title' => 'Source'], $this->definition()->toArray()['dataset']);
    }

    public function testASourceIsListedUnderTheDataSetItDeclares(): void
    {
        $definition = $this->definition(dataset: 'shared', datasetTitle: 'Shared');

        $this->assertSame(['id' => 'shared', 'title' => 'Shared'], $definition->toArray()['dataset']);
    }

    /**
     * A data set without a title cannot be shown, and a title without a
     * data set names nothing.
     */
    public function testItRefusesADataSetWithoutATitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->definition(dataset: 'shared');
    }

    public function testItRefusesADataSetTitleWithoutADataSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->definition(datasetTitle: 'Shared');
    }

    private function definition(?string $dataset = null, ?string $datasetTitle = null): Definition
    {
        return new Definition(
            id: 'source',
            title: 'Source',
            description: '',
            publisher: '',
            contact: '',
            landingPage: '',
            accessUrl: 'https://example.com',
            dataType: DataType::GeoJSON,
            mediaType: 'application/geo+json',
            crs: 'EPSG:4326',
            model: 'Bench',
            contextUrl: 'https://example.com/context.jsonld',
            updateFrequency: '',
            licence: null,
            omittedFields: [],
            dataset: $dataset,
            datasetTitle: $datasetTitle,
        );
    }
}
