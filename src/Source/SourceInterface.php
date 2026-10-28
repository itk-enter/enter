<?php

declare(strict_types=1);

namespace App\Source;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Converts the records of one feed into NGSI-LD entities of one data model.
 *
 * An implementation knows the origin of its feed, the field names, the
 * special cases and the Smart Data Model. The broker and the import do not
 * know these things. Thus, one class adds one data set.
 */
#[AutoconfigureTag('app.source')]
interface SourceInterface extends \Stringable, \JsonSerializable
{
    public Definition $definition {
        get;
    }

    /**
     * Makes an NgsiEntity from one feed record.
     *
     * The importer calls this method one time for each record that it gets
     * from the feed. The importer adds the attribute sourceId and upserts the
     * entity into the broker.
     *
     * If the method returns null, the importer skips the record and does not
     * log it. Return null for a record that is not applicable, for example a
     * record without an ID or without a geometry. If the method throws an
     * exception, the importer logs an error and skips the record.
     *
     * The entity must have the model of the #[Definition] as its type. Use
     * $transformer to change the geometry from the CRS of the feed to WGS84.
     *
     * For example, a GeoJSON feature with the properties {"mi_prinx": 7,
     * "navn": "Mølleparken"} gives an entity with the ID
     * urn:ngsi-ld:PublicToilet:aarhus-toilet-city-7 and the name
     * "Mølleparken".
     *
     * @param array<string, mixed> $data one record, as the importer gets it
     *                                   from the feed: a GeoJSON feature or
     *                                   an Overpass element
     *
     * @return NgsiEntity|null the entity, or null to skip the record
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
