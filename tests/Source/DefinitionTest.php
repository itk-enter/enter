<?php

declare(strict_types=1);

namespace App\Tests\Source;

use App\Source\DataType;
use App\Source\Definition;
use PHPUnit\Framework\TestCase;

class DefinitionTest extends TestCase
{
    public function testASourceIsListedUnderTheDataSetItDeclares(): void
    {
        $definition = $this->definition(dataset: 'shared', datasetTitle: 'Shared');

        $this->assertSame(['id' => 'shared', 'title' => 'Shared'], $definition->toArray()['dataset']);
    }

    private function definition(string $dataset, string $datasetTitle): Definition
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
