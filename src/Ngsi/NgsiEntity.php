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
    /**
     * The names that an attribute must not have.
     *
     * The payload uses id, type and @context for the entity. The broker
     * drops createdAt and modifiedAt, because it sets them itself, and it
     * stores objects and values under other names. NGSI-LD uses the other
     * names for the data of the broker and for the metadata of an attribute.
     */
    private const array RESERVED_ATTRIBUTE_NAMES = [
        'id', 'type', '@context',
        'createdAt', 'modifiedAt', 'deletedAt', 'observedAt', 'datasetId', 'instanceId', 'unitCode',
        'objects', 'values',
    ];

    /**
     * The keys that the value of an attribute must not have, at any depth.
     * The broker drops them without an error.
     */
    private const array RESERVED_VALUE_KEYS = ['createdAt', 'modifiedAt'];

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

    public function type(): string
    {
        return $this->type;
    }

    /**
     * A copy of the entity with another id, and the same type and attributes.
     */
    public function withId(string $id): self
    {
        $copy = new self($id, $this->type);
        $copy->attributes = $this->attributes;

        return $copy;
    }

    public function has(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * The attribute in normalized form, or null if the entity does not have
     * it.
     *
     * @return array<string, mixed>|null
     */
    public function attribute(string $name): ?array
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Sets an attribute in normalized form, with all its sub-attributes.
     *
     * @param array<string, mixed> $attribute
     *
     * @throws ReservedNameException if NGSI-LD reserves the name, or a key in the value
     */
    public function setAttribute(string $name, array $attribute): self
    {
        if (\in_array($name, self::RESERVED_ATTRIBUTE_NAMES, true)) {
            throw ReservedNameException::attributeName($name);
        }
        self::assertNoReservedKeys($name, $attribute['value'] ?? null);

        $this->attributes[$name] = $attribute;

        return $this;
    }

    /**
     * Sets a property, and ignores an empty value.
     *
     * @throws ReservedNameException if NGSI-LD reserves the name, or a key in the value
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

        return $this->setAttribute($name, $attribute);
    }

    /**
     * Sets the source fields that the model has no attribute for.
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
     *
     * @throws ReservedNameException if NGSI-LD reserves the name
     */
    public function geoProperty(string $name, array $geoJson): self
    {
        return $this->setAttribute($name, ['type' => 'GeoProperty', 'value' => $geoJson]);
    }

    /**
     * Fails if a key in the value, at any depth, is a key that the broker drops.
     *
     * @throws ReservedNameException
     */
    private static function assertNoReservedKeys(string $attribute, mixed $value): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (\in_array($key, self::RESERVED_VALUE_KEYS, true)) {
                throw ReservedNameException::keyInValue($attribute, $key);
            }
            self::assertNoReservedKeys($attribute, $item);
        }
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
