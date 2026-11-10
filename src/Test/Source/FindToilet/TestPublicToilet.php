<?php

declare(strict_types=1);

namespace App\Test\Source\FindToilet;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
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
final class TestPublicToilet extends AbstractSource
{
    /**
     * Site categories that the model has properties for.
     */
    private const array MAPPED_CATEGORIES = ['handicap', 'unisex', 'pissoir', 'changingplace'];

    /**
     * Makes an NgsiEntity from one feed record.
     *
     * @param array<string, mixed> $data findtoilet.dk API v3 toilet record
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $id = $data['id'] ?? null;
        if (null === $id || '' === $id) {
            return null;
        }

        $location = \is_array($data['location'] ?? null) ? $data['location'] : [];
        $latitude = $location['lat'] ?? null;
        $longitude = $location['long'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $geometry = ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]];

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-findtoilet-%s', $this->definition->model, $id),
            $this->definition->model
        );

        [$placement, $openingHours] = $this->description((string) ($data['description'] ?? ''));
        $category = trim((string) ($data['type'] ?? ''));

        return $entity
            ->setProperty('name', trim((string) ($data['title'] ?? '')))
            ->setProperty('address', $this->address($location))
            ->setProperty('contactPoint', $this->contactPoint($data))
            ->setProperty('wheelchairAccessible', 'handicap' === $category ? 'yes' : null)
            ->setProperty('genderCategory', 'unisex' === $category ? ['unisex'] : [])
            ->setProperty('toiletPosition', 'pissoir' === $category ? ['urinal'] : [])
            ->setProperty('changingPlace', 'changingplace' === $category ? true : null)
            ->setProperty('staffed', $this->flag($data['manned'] ?? null))
            ->setProperty('handwashing', $this->flag($data['tap'] ?? null))
            ->setProperty('babyChange', $this->flag($data['changing_table'] ?? null))
            ->setProperty('sharpsDisposal', $this->flag($data['needle_container'] ?? null))
            ->setProperty('isAccessibleForFree', $this->isAccessibleForFree($data))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))

            ->additionalInformation([
                'category' => \in_array($category, self::MAPPED_CATEGORIES, true) ? '' : $category,
                'placement' => $placement,
                'addressAdditional' => trim((string) ($location['additional'] ?? '')),
                'openingHours' => $openingHours,
                'images' => array_column(\is_array($data['images'] ?? null) ? $data['images'] : [], 'url') ?: null,
            ]);
    }

    /**
     * @param array<string, mixed> $location
     *
     * @return array<string, string>
     */
    private function address(array $location): array
    {
        return array_filter([
            'streetAddress' => trim((string) ($location['street'] ?? '')),
            'postalCode' => trim((string) ($location['postal_code'] ?? '')),
            'addressLocality' => trim((string) ($location['city'] ?? '')),
            'addressCountry' => strtoupper(trim((string) ($location['country'] ?? ''))),
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * Returns the contact point for fault reports.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function contactPoint(array $data): array
    {
        $email = trim((string) ($data['kontakt'] ?? ''));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        return ['contactType' => 'fault reporting', 'email' => $email];
    }

    /**
     * Changes a 0/1 flag into a boolean.
     */
    private function flag(mixed $value): ?bool
    {
        return match ($value) {
            '0' => false,
            '1' => true,
            default => null,
        };
    }

    /**
     * Returns true if the toilet is free of charge.
     *
     * @param array<string, mixed> $data
     */
    private function isAccessibleForFree(array $data): ?bool
    {
        return match ($data['payment'] ?? null) {
            '0' => true,
            '1' => false,
            default => null,
        };
    }

    /**
     * Gets the placement and the opening hours from the HTML description.
     *
     * @return array{0: string, 1: string} [placement, openingHours]
     */
    private function description(string $html): array
    {
        $placement = '';
        $openingHours = '';

        if (preg_match('/<b>Placering:<\/b>\s*([^\r\n]*)/u', $html, $matches)) {
            $placement = trim($matches[1]);
        }

        if (preg_match('/<b>Åbningstider:<\/b>\s*([^\r\n]*)/u', $html, $matches)) {
            $openingHours = trim($matches[1]);
        }

        return [$placement, $openingHours];
    }
}
