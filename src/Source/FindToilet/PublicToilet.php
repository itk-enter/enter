<?php

declare(strict_types=1);

namespace App\Source\FindToilet;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Public toilets in Aarhus Municipality that findtoilet.dk lists.
 */
#[Definition(
    id: 'findtoilet-public-toilet',
    title: 'Offentlige toiletter (FindToilet), Aarhus Kommune',
    description: 'Public toilets in Aarhus Municipality listed on findtoilet.dk.',
    publisher: 'findtoilet.dk',
    contact: 'findtoilet@findtoilet.dk',
    landingPage: 'https://beta.findtoilet.dk/',

    accessUrl: [
        'url' => 'https://beta.findtoilet.dk/api/v3/toilets',
        'query' => ['tid' => 8],
    ],
    dataType: DataType::FindToilet,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',

    licence: null,

    omittedFields: [
        'region' => 'Constant for this municipality-scoped feed; the data set\'s own scope.',
        'kontakttitle' => 'The label the site shows for kontakt; constant and identical to it throughout the feed.',
    ],
    dataset: 'findtoilet-public-toilet',
    datasetTitle: 'Offentlige toiletter (FindToilet), Aarhus Kommune',
)]
final class PublicToilet extends AbstractPublicToilet
{
}
