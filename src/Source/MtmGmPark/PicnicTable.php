<?php

declare(strict_types=1);

namespace App\Source\MtmGmPark;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Picnic tables with benches in the parks, forests and streets of Aarhus
 * Municipality. People can sit on both sides of the table.
 */
#[Definition(
    id: 'mtm_gmpark-picnic-table',
    title: 'Bordbænkesæt (MTM), Aarhus Kommune',
    description: 'Picnic tables with benches in the parks, forests and streets of Aarhus Municipality.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: '',

    accessUrl: 'https://wfs-ext.gmpark.dk/geoserver/ws_2b1b3726-07ea-4872-bda9-6982d717dc2c/ows?service=WFS&version=1.0.0&request=GetFeature&typeName=ws_2b1b3726-07ea-4872-bda9-6982d717dc2c:ud14&outputFormat=application/json',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',

    licence: null,

    omittedFields: self::OMITTED_FIELDS,
    dataset: 'mtm_gmpark-picnic-table',
    datasetTitle: 'Bordbænkesæt (MTM), Aarhus Kommune',
)]
final class PicnicTable extends AbstractBench
{
    protected function kind(): string
    {
        return 'picnicTable';
    }

    protected function twoSided(): ?bool
    {
        return true;
    }
}
