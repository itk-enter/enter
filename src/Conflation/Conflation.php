<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Conflation\Step\AbstractAugmentation;
use App\Conflation\Step\AbstractConflictResolution;
use App\Conflation\Step\AbstractMergeByLocation;

/**
 * The configuration of a merge.
 *
 * A merge is a class carrying this attribute and implementing
 * ConflationInterface. Each customizable step is named by an implementation
 * of its abstract class, so a kind that needs other rules for a step names a
 * subclass there.
 *
 * @see ConflationInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Conflation
{
    /**
     * @param string                           $id                 the merged data set's source identifier
     * @param string                           $resultModel        the data model merged entities are built in
     * @param list<string>                     $defining           models whose records are matched, each able to form
     *                                                             a merged entity
     * @param list<AbstractConflictResolution> $conflictResolution approaches tried in order on an attribute in conflict;
     *                                                             with none, every conflict is left out and recorded
     * @param array<string, list<string>>      $augmenting         models that only add attributes, with the attributes
     *                                                             each supplies, named as in the result model
     */
    public function __construct(
        public string $id,
        public string $resultModel,
        public array $defining,
        public AbstractMergeByLocation $mergeByLocation,
        public array $conflictResolution = [],
        public array $augmenting = [],
        public ?AbstractAugmentation $augmentation = null,
    ) {
        if ([] === $defining) {
            throw new \InvalidArgumentException(\sprintf('Merge %s names no defining model.', $id));
        }

        foreach ($conflictResolution as $approach) {
            if (!$approach instanceof AbstractConflictResolution) {
                throw new \InvalidArgumentException(\sprintf('Merge %s lists %s as a conflict-resolution approach; it must extend %s.', $id, get_debug_type($approach), AbstractConflictResolution::class));
            }
        }

        $both = array_intersect($defining, array_keys($augmenting));
        if ([] !== $both) {
            throw new \InvalidArgumentException(\sprintf('Merge %s names %s both as defining and as augmenting.', $id, implode(', ', $both)));
        }

        if ([] !== $augmenting && null === $augmentation) {
            throw new \InvalidArgumentException(\sprintf('Merge %s names augmenting models but no augmentation.', $id));
        }
    }

    /**
     * The configuration a merge class declares.
     *
     * Reading it takes no instance, as with a source's definition.
     *
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    public static function of(string $class): self
    {
        $attribute = new \ReflectionClass($class)->getAttributes(self::class)[0]
            ?? throw new \LogicException(\sprintf('Merge %s declares no #[%s] attribute.', $class, self::class));

        return $attribute->newInstance();
    }

    public function isDefining(string $model): bool
    {
        return \in_array($model, $this->defining, true);
    }

    public function isAugmenting(string $model): bool
    {
        return \array_key_exists($model, $this->augmenting);
    }
}
