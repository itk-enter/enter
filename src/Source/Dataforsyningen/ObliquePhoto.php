<?php

declare(strict_types=1);

namespace App\Source\Dataforsyningen;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Skraafoto\SkraafotoClient;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;

/**
 * One oblique aerial photo (skråfoto) for each merged entity that a merge
 * wants a photo for.
 *
 * The data set does not hold all the photos of Dataforsyningen. It holds only
 * the photos that the merges use. SkraafotoReader finds the photos. A merge
 * names "ObliquePhoto" as an augmenting model to add the attribute "image"
 * to its merged entities.
 *
 * An entity has the location of its merged entity, so the merge finds the
 * photo again at that location. The attribute "image" is the address of the
 * thumbnail on this application, because the address of Dataforsyningen
 * must contain the token.
 */
#[Definition(
    id: 'dataforsyningen-skraafoto',
    title: 'Skråfotos (Dataforsyningen)',
    description: 'One oblique aerial photo for each merged entity that a merge adds a photo to, from the Skråfoto API of Dataforsyningen.',
    publisher: 'Klimadatastyrelsen',
    contact: 'https://dataforsyningen.dk/',
    landingPage: 'https://skraafoto.dataforsyningen.dk/',

    accessUrl: SkraafotoClient::STAC_URL,
    dataType: DataType::Skraafoto,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'ObliquePhoto',
    contextUrl: 'https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld',
    updateFrequency: 'annual',
    licence: 'https://dataforsyningen.dk/Vilkaar',

    omittedFields: [
        'pers' => 'The pers: properties tell how the camera was placed. A thumbnail does not need them.',
        'gsd' => 'The size on the ground of one pixel of the original photo, not of the thumbnail.',
    ],
    dataset: 'dataforsyningen-skraafoto',
    datasetTitle: 'Skråfotos (Dataforsyningen)',
)]
final class ObliquePhoto extends AbstractSource
{
    /**
     * Makes an NgsiEntity from one item that SkraafotoReader gives.
     *
     * @param array<string, mixed> $data
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $entity = $data['entity'] ?? null;
        $photo = $data['photo'] ?? null;
        $collection = $data['collection'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $latitude = $data['latitude'] ?? null;
        if (!\is_string($entity) || !\is_string($photo) || !\is_string($collection) || !is_numeric($longitude) || !is_numeric($latitude)) {
            return null;
        }

        // The last part of the id of the merged entity is unique, for example "merged-public-toilets-aarhus-toilet-city-123".
        $localId = substr($entity, (int) strrpos($entity, ':') + 1);

        return new NgsiEntity(\sprintf('urn:ngsi-ld:%s:skraafoto-%s', $this->definition->model, $localId), $this->definition->model)
            ->setProperty('image', $data['image'] ?? null)
            ->setProperty('source', \sprintf('%s/collections/%s/items/%s', SkraafotoClient::STAC_URL, rawurlencode($collection), rawurlencode($photo)))
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]]))
            ->additionalInformation([
                'entity' => $entity,
                'collection' => $collection,
                'photo' => $photo,
                'direction' => $data['direction'] ?? null,
                'datetime' => $data['datetime'] ?? null,
            ]);
    }
}
