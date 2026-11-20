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

    /**
     * The page of a model is the folder of the model next to the context,
     * also when the context is on a tag with a slash in its name.
     */
    public function testTheModelUrlIsTheFolderOfTheModelNextToTheContext(): void
    {
        $this->assertSame(
            'https://github.com/smart-data-models/dataModel.Parking/tree/master/ParkingSpot',
            Definition::modelUrlOf('https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld', 'ParkingSpot'),
        );
        $this->assertSame(
            'https://github.com/itk-enter/data-models/tree/Bench/v0.0.1/dataModel.PointOfInterest/Bench',
            Definition::modelUrlOf('https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld', 'Bench'),
        );
    }

    public function testAContextOutsideGitHubGivesNoModelUrl(): void
    {
        $this->assertNull(Definition::modelUrlOf('https://example.com/context.jsonld', 'Bench'));
        $this->assertNull($this->definition(dataset: 'shared', datasetTitle: 'Shared')->modelUrl());
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
