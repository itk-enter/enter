<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Source\Definition;
use App\Source\SourceInterface;
use App\Test\Source\TestDefinition;

/**
 * A source that is nothing but its definition.
 *
 * What reads a source around the map is its metadata, never its mapping, so
 * a test only has to say which data set the source stands for.
 */
final class FakeSource implements SourceInterface
{
    private function __construct(
        private readonly TestDefinition $given,
    ) {
    }

    public static function create(
        string $title,
        string $accessUrl,
        DataType $dataType = DataType::GeoJSON,
        ?string $id = null,
    ): self {
        return new self(new TestDefinition(
            id: $id ?? strtolower($title),
            title: $title,
            accessUrl: $accessUrl,
            dataType: $dataType,
            mediaType: 'application/geo+json',
            crs: 'EPSG:4326',
            model: 'OnStreetParking',
            contextUrl: 'https://example.com/context.jsonld',
            omittedFields: [],
            dataUrlBase: 'https://example.com/data',
        ));
    }

    public Definition $definition {
        get => $this->given;
    }

    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [];
    }

    public function __toString(): string
    {
        return $this->given->title;
    }
}
