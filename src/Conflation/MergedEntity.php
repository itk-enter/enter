<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * One real-world thing as a merge resolves it, before it is built in the
 * merge's result model.
 *
 * Only the pipeline creates merged entities. The steps a merge customizes
 * receive them, and return groups or values rather than entities, so they
 * cannot add, remove or move one.
 */
final readonly class MergedEntity
{
    /**
     * @param string                           $id         the identifier of the member that sorts first
     * @param array<string, mixed>             $location   GeoJSON geometry
     * @param array<string, mixed>             $attributes settled values, by attribute name
     * @param list<string>                     $provenance identifiers of every input record it was built from, sorted
     * @param array<string, list<StatedValue>> $conflicts  the values of attributes no approach settled, by attribute name
     */
    public function __construct(
        public string $id,
        public array $location,
        public array $attributes,
        public array $provenance,
        public array $conflicts = [],
    ) {
    }

    public function has(string $attribute): bool
    {
        return \array_key_exists($attribute, $this->attributes) || \array_key_exists($attribute, $this->conflicts);
    }

    /**
     * The entity with attributes added by augmenting.
     *
     * @param array<string, mixed>             $attributes
     * @param array<string, list<StatedValue>> $conflicts
     * @param list<string>                     $recordIds  the augmenting records that supplied them
     */
    public function augmentedWith(array $attributes, array $conflicts, array $recordIds): self
    {
        $provenance = array_values(array_unique([...$this->provenance, ...$recordIds]));
        sort($provenance, \SORT_STRING);
        $attributes = [...$this->attributes, ...$attributes];
        ksort($attributes, \SORT_STRING);
        $conflicts = [...$this->conflicts, ...$conflicts];
        ksort($conflicts, \SORT_STRING);

        return new self($this->id, $this->location, $attributes, $provenance, $conflicts);
    }
}
