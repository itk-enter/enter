<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Public toilets outside the city-kiosk network in Aarhus Municipality.
 */
#[Definition(
    id: 'mtm_spatialmaps-toilet-other',
    title: 'Andre toiletter (MTM), Aarhus Kommune',
    description: 'Public toilets outside the city-kiosk network in Aarhus Municipality, including seasonal and access-restricted facilities.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: 'https://www.opendata.dk/city-of-aarhus/toiletter-i-aarhus-kommune',

    accessUrl: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=andre_toiletter',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',

    licence: null,

    omittedFields: [
        'bookbar' => 'Bookable flag; constant "Nej" throughout the export.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record; personal data, and not a fact about the toilet.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record; personal data, and not a fact about the toilet.',
        'mi_style' => 'MapInfo rendering style.',
    ],
    dataset: 'mtm_spatialmaps-toilet-other',
    datasetTitle: 'Andre toiletter (MTM), Aarhus Kommune',
)]
final class ToiletOther extends AbstractToiletOther
{
}
