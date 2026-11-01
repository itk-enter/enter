<?php

declare(strict_types=1);

namespace App\Source\MtmGmPark;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * Benches in the GM Park register of Aarhus Municipality.
 *
 * The register gives each kind of bench in a feed of its own, with the same
 * fields. Each feed is a source of its own, and all of them publish the Bench
 * model. A subclass gives the definition of its feed and tells if its benches
 * are two-sided.
 */
abstract class AbstractBench extends AbstractSource
{
    /**
     * The status of a bench that stands in the register.
     */
    private const string ACTIVE = 'active';

    /**
     * The fields that each source leaves out, with the reason.
     */
    protected const array OMITTED_FIELDS = [
        'elementtypekey' => 'Selector of the feed; every record of a source is the same kind of bench.',
        'elementtypename' => 'Name of the kind of bench; every record of a source is the same kind.',
        'element_description' => 'Copy of description.',
        'measure' => 'Number of units; constant 1 throughout the export.',
        'unittype' => 'Unit of measure; constant "stk." throughout the export.',
        'perimeter' => 'Perimeter of the geometry; every geometry is a point.',
        'geometrytype' => 'Type of the geometry; every geometry is a point.',
        'status' => 'Decides whether a record is published; every published record is active.',
        'origin' => 'Whether the record was made in the office or in the field; describes the register, not the bench.',
        'createdby' => 'Name of the municipal employee who created the record; personal data, and not a fact about the bench.',
        'updatedby' => 'Name of the municipal employee who last edited the record; personal data, and not a fact about the bench.',
        'days_from_creation' => 'Age of the record in days on the day of the export; changes each day.',
        'days_from_update' => 'Days since the last edit on the day of the export; changes each day.',
        'level_1_workareas' => 'Internal municipal work area, not a fact about the bench.',
        'level_1_workareatypes' => 'Kind of the internal municipal work area.',
        'level_2_workareas' => 'Internal municipal work areas, not a fact about the bench.',
        'level_2_workareatypes' => 'Kinds of the internal municipal work areas.',
        'att_aargang' => 'Code with values such as 2010 and 0; its meaning is not documented.',
        'att_stat_s' => 'Code with the values 1 and 2; its meaning is not documented.',
        'attachment1' => 'Reference to a file in the register; not available to the public.',
    ];

    /**
     * Makes an NgsiEntity from one feed record.
     *
     * @param array<string, mixed> $data GeoJSON Feature
     */
    final public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $row = $data['properties'] ?? null;
        $geometry = $data['geometry'] ?? null;

        if (!\is_array($row) || !\is_array($geometry)) {
            return null;
        }

        $key = trim((string) ($row['elementid'] ?? ''));
        if ('' === $key || self::ACTIVE !== ($row['status'] ?? null)) {
            return null;
        }

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-bench-gmpark-%s', $this->definition->model, $key),
            $this->definition->model
        );

        return $entity
            ->setProperty('description', trim((string) ($row['description'] ?? '')))
            ->setProperty('address', $this->address($row))
            ->setProperty('twoSided', $this->twoSided())
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))
            ->additionalInformation([
                'elementName' => trim((string) ($row['elementname'] ?? '')),
                'registeredAt' => trim((string) ($row['created'] ?? '')),
                'updatedAt' => trim((string) ($row['updated'] ?? '')),
            ]);
    }

    /**
     * Tells if people can sit on both sides of the benches of the feed, or
     * returns null if the feed does not tell.
     */
    abstract protected function twoSided(): ?bool;

    /**
     * Returns the postal address of the bench. The register states it as
     * "street and number, postal code and town".
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function address(array $row): array
    {
        $address = trim((string) ($row['address'] ?? ''));
        if (1 !== preg_match('/^(.+?),\s*(\d{4})\s+(.+)$/u', $address, $match)) {
            return array_filter(['streetAddress' => $address], static fn (string $value): bool => '' !== $value);
        }

        return ['streetAddress' => trim($match[1]), 'postalCode' => $match[2], 'addressLocality' => trim($match[3])];
    }
}
