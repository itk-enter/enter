<?php

namespace App;

use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SourceManager
{
    /**
     * @param iterable<SourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.source')]
        private iterable $sources,
    ) {
        $indexed = [];
        foreach ($sources as $source) {
            if (!$source instanceof SourceInterface) {
                throw new \InvalidArgumentException(sprintf('Invalid source class: %s (must implement %s)', $source::class, SourceInterface::class));
            }
            $id = $source->definition->id;
            if (isset($indexed[$id])) {
                throw new \RuntimeException(sprintf('Duplicate source: %s (ID already used by %s)', $id, $indexed[$id]::class));
            }
            $indexed[$id] = $source;
        }
        $this->sources = $indexed;
    }

    /**
     * Get sources indexed by ID.
     *
     * @return array<string, SourceInterface>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getSource(string $name): SourceInterface
    {
        $sources = $this->getSources();
        if (!array_key_exists($name, $sources)) {
            throw new \InvalidArgumentException(sprintf('Source "%s" does not exist.', $name));
        }

        return $sources[$name];
    }
}
