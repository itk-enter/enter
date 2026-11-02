<?php

declare(strict_types=1);

namespace App\Conflation\Data;

/**
 * One real-world thing as a merge resolves it, before the create step builds
 * it in the result model of the merge.
 *
 * Only the pipeline creates merged entities. The steps that a merge
 * customizes receive them and return groups or values, not entities. Thus
 * these steps cannot add, remove or move a merged entity.
 */
final readonly class MergedEntity
{
    /**
     * @param string                           $id         the identifier of the member that sorts first
     * @param array<string, mixed>             $location   GeoJSON geometry
     * @param array<string, mixed>             $attributes settled values, by attribute name
     * @param list<string>                     $provenance the sorted identifiers of every input record that the merge
     *                                                     built it from
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
     * The entity with the attributes that the augmentation added.
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
