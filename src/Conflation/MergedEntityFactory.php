<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Ngsi\NgsiEntity;
use App\SourceImporter\AbstracSourceImporter;

/**
 * Builds the entity a merged entity is published as: the create step.
 *
 * Every merged entity is built in the merge's result model, with the
 * attributes its members settled on, plus what every merged entity carries:
 *
 * - id: urn:ngsi-ld:<result model>:<merge id>-<local id of the member that
 *   sorts first>. It stays the same from run to run while that member does,
 *   and never equals the id of an input entity.
 * - sourceId: the merge's id, as the importer stamps a source's id.
 * - seeAlso: the ids of every input entity it was built from, its provenance.
 * - additionalInformation: put back together from the keys the fetch step
 *   split it into, with the attributes no approach settled under "conflicts".
 *
 * For example, a toilet stated by the municipal register as
 * urn:ngsi-ld:PublicToilet:aarhus-toilet-city-123 and by OSM as
 * urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17, disagreeing on its
 * opening hours, becomes
 *
 *     {"id": "urn:ngsi-ld:PublicToilet:merged-public-toilets-aarhus-toilet-city-123", "type": "PublicToilet",
 *      "name": …, "location": …, "sourceId": "merged-public-toilets",
 *      "seeAlso": ["urn:ngsi-ld:PublicToilet:aarhus-toilet-city-123", "urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17"],
 *      "additionalInformation": {"conflicts": {"openingHours": [
 *          {"value": "08–20", "dataSet": "mtm_spatialmaps-toilet-city", "entity": "urn:…:aarhus-toilet-city-123"},
 *          {"value": "06–22", "dataSet": "osm-public-toilet", "entity": "urn:…:aarhus-toilet-osm-node-17"}]}}}
 */
final readonly class MergedEntityFactory
{
    /**
     * The key of additionalInformation that holds the attributes no approach
     * settled.
     */
    public const string CONFLICTS = 'conflicts';

    /**
     * The model attribute provenance is published as: a list of URIs, here
     * the ids of the input entities.
     */
    public const string PROVENANCE = 'seeAlso';

    public function create(Conflation $conflation, MergedEntity $entity): NgsiEntity
    {
        $prefix = ConflationFetcher::ADDITIONAL_INFORMATION.ConflationFetcher::KEY_SEPARATOR;

        $result = new NgsiEntity(self::id($conflation, $entity->id), $conflation->resultModel);
        $information = [];
        foreach ($entity->attributes as $attribute => $value) {
            if (str_starts_with($attribute, $prefix)) {
                $information[substr($attribute, \strlen($prefix))] = $value;
            } else {
                $result->setProperty($attribute, $value);
            }
        }

        if ([] !== $entity->conflicts) {
            $information[self::CONFLICTS] = array_map(
                static fn (array $values): array => array_map(
                    static fn (StatedValue $value): array => ['value' => $value->value, 'dataSet' => $value->sourceId, 'entity' => $value->recordId],
                    $values,
                ),
                $entity->conflicts,
            );
        }
        ksort($information, \SORT_STRING);

        /** @var array{type: string, coordinates: mixed} $location */
        $location = $entity->location;

        return $result
            ->geoProperty('location', $location)
            ->setProperty(AbstracSourceImporter::SOURCE_ID_ATTRIBUTE, $conflation->id)
            ->setProperty(self::PROVENANCE, $entity->provenance)
            ->setProperty(ConflationFetcher::ADDITIONAL_INFORMATION, $information);
    }

    /**
     * The id a merged entity is published under, from the id of its member
     * that sorts first.
     */
    public static function id(Conflation $conflation, string $memberId): string
    {
        return \sprintf('urn:ngsi-ld:%s:%s-%s', $conflation->resultModel, $conflation->id, self::localId($memberId));
    }

    /**
     * What follows urn:ngsi-ld:<type>: in an entity id, or the whole id where
     * it has no such prefix.
     */
    private static function localId(string $id): string
    {
        return 1 === preg_match('/^urn:ngsi-ld:[^:]+:(.+)$/', $id, $match) ? $match[1] : $id;
    }
}
