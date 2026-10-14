<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;

/**
 * A toilet data set declaring the earlier v0.0.1 of the PublicToilet context,
 * where the other toilet data sets declare v0.0.2, and a licence of its own.
 */
#[Definition(
    id: 'toilet-of-another-version',
    title: 'Toilets of another version',
    description: 'Toilets published in an earlier version of the model.',
    publisher: 'Example publisher',
    contact: 'toilets@example.org',
    landingPage: 'https://example.org/toilets',
    accessUrl: 'https://example.org/toilets.geojson',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'daily',
    licence: 'https://creativecommons.org/licenses/by/4.0/',
    omittedFields: [],
)]
final class ToiletOfAnotherVersion extends AbstractSource
{
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        return null;
    }
}
