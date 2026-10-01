<?php

declare(strict_types=1);

namespace App\Ngsi;

/**
 * A single NGSI-LD entity in normalized form.
 *
 * @see https://www.etsi.org/deliver/etsi_gs/CIM/001_099/009/01.06.01_60/gs_CIM009v010601p.pdf
 */
final class NgsiEntity
{
    /** @var array<string, array<string, mixed>> */
    private array $attributes = [];

    public function __construct(
        private readonly string $id,
        private readonly string $type,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Null and empty-string values are dropped rather than emitted as null,
     * because the source data uses "" for "not filled in" and a broker would
     * otherwise store the emptiness as a fact.
     */
    public function setProperty(string $name, mixed $value, ?string $observedAt = null): self
    {
        if (null === $value || '' === $value || [] === $value) {
            return $this;
        }

        $attribute = ['type' => 'Property', 'value' => $value];

        if (null !== $observedAt) {
            $attribute['observedAt'] = $observedAt;
        }

        $this->attributes[$name] = $attribute;

        return $this;
    }

    /**
     * Source fields the model has no attribute for, gathered under one term.
     *
     * @param array<string, mixed> $fields
     */
    public function additionalInformation(array $fields): self
    {
        $isPresent = static fn (mixed $value): bool => null !== $value && '' !== $value;

        return $this->setProperty('additionalInformation', array_filter($fields, $isPresent));
    }

    /**
     * @param array{type: string, coordinates: mixed} $geoJson
     */
    public function geoProperty(string $name, array $geoJson): self
    {
        $this->attributes[$name] = ['type' => 'GeoProperty', 'value' => $geoJson];

        return $this;
    }

    public function relationship(string $name, string $object): self
    {
        $this->attributes[$name] = ['type' => 'Relationship', 'object' => $object];

        return $this;
    }

    /**
     * @param list<string> $contextUrls
     *
     * @return array<string, mixed>
     */
    public function toPayload(array $contextUrls): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            ...$this->attributes,
            '@context' => $contextUrls,
        ];
    }
}
