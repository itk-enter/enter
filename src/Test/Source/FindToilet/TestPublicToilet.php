<?php

declare(strict_types=1);

namespace App\Test\Source\FindToilet;

use App\Source\DataType;
use App\Source\FindToilet\AbstractPublicToilet;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Public toilets in Aarhus Municipality that findtoilet.dk lists.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:findtoilet-public-toilet',
    title: 'Test: Offentlige toiletter (FindToilet), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/beta.findtoilet.dk/api/v3/toilets?findtoilet-public-toilet',
    dataType: DataType::FindToilet,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'region' => 'Constant for this municipality-scoped feed; the data set\'s own scope.',
        'kontakttitle' => 'The label the site shows for kontakt; constant and identical to it throughout the feed.',
    ],
    dataUrlBase: 'https://beta.findtoilet.dk/api/v3/toilets',
    dataUrlQuery: ['tid' => 8],
    dataset: 'test:findtoilet-public-toilet',
    datasetTitle: 'Test: Offentlige toiletter (FindToilet), Aarhus Kommune',
)]
final class TestPublicToilet extends AbstractPublicToilet
{
}
