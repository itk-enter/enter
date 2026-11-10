<?php

namespace App\Source;

/**
 * The base class of a source.
 *
 * The class reads the #[Definition] attribute of the concrete source. It
 * gives the key, the string and the JSON of the source from that attribute.
 * A concrete source adds the #[Definition] attribute and implements
 * createNgsiEntity().
 */
abstract class AbstractSource implements SourceInterface
{
    /**
     * The #[Definition] attribute of the concrete source.
     */
    public Definition $definition {
        /**
         * @throws \ReflectionException
         */
        get => Definition::of(static::class);
    }

    public function key(): string
    {
        return $this->definition->id;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->definition->title, $this->definition->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
