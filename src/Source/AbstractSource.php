<?php

namespace App\Source;

/**
 * Abstract source.
 */
abstract class AbstractSource implements SourceInterface
{
    /**
     * Read from the #[Definition] attribute on the concrete source.
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
