<?php

declare(strict_types=1);

namespace App\Conflation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every merge declared, by its id.
 */
final readonly class ConflationManager
{
    /** @var array<string, Conflation> */
    private array $conflations;

    /**
     * @param iterable<ConflationInterface> $merges
     *
     * @throws \ReflectionException
     */
    public function __construct(
        #[AutowireIterator('app.conflation')]
        iterable $merges,
    ) {
        $conflations = [];
        $classes = [];
        foreach ($merges as $merge) {
            $conflation = Conflation::of($merge::class);
            if (isset($conflations[$conflation->id])) {
                throw new \RuntimeException(\sprintf('Duplicate merge: %s (id already used by %s)', $conflation->id, $classes[$conflation->id]));
            }
            $conflations[$conflation->id] = $conflation;
            $classes[$conflation->id] = $merge::class;
        }
        ksort($conflations, \SORT_STRING);
        $this->conflations = $conflations;
    }

    /**
     * @return array<string, Conflation>
     */
    public function getConflations(): array
    {
        return $this->conflations;
    }

    public function getConflation(string $id): Conflation
    {
        return $this->conflations[$id] ?? throw new \InvalidArgumentException(\sprintf('Merge "%s" does not exist.', $id));
    }
}
