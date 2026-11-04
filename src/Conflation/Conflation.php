<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Conflation\Augmentation\AbstractAugmentation;
use App\Conflation\Augmentation\WithinAreaOrRadius;
use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\MergeByLocation\AbstractMergeByLocation;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;

/**
 * The configuration of a merge.
 *
 * A merge is a class that has this attribute and implements
 * ConflationInterface. The attribute names an implementation of the abstract
 * class of each customizable step. If a kind of thing needs other rules for
 * a step, the merge names a subclass for that step.
 *
 * @see ConflationInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Conflation
{
    /**
     * How augmenting records reach merged entities; null when the merge names
     * no augmenting model.
     */
    public ?AbstractAugmentation $augmentation;

    /**
     * @param string                           $id                 the merged data set's source identifier, stamped on
     *                                                             every merged entity as its sourceId
     * @param string                           $title              the merged data set's title, as a source's
     * @param string                           $description        what the merged data set holds, as a source's
     * @param string                           $resultModel        the data model merged entities are built in
     * @param list<string>                     $defining           the models whose records the merge matches; each one
     *                                                             can form a merged entity
     * @param list<AbstractConflictResolution> $conflictResolution the approaches that the pipeline tries in order on an
     *                                                             attribute in conflict; with none, the merge leaves
     *                                                             out and records every conflict
     * @param array<string, list<string>>      $augmenting         models that only add attributes, with the attributes
     *                                                             each supplies, named as in the result model
     * @param AbstractAugmentation|null        $augmentation       how augmenting records reach merged entities; if not
     *                                                             given, WithinAreaOrRadius with the merge's radius
     * @param string|null                      $contextUrl         the result model's JSON-LD context; if not given, the
     *                                                             one that the input data sets of the result model
     *                                                             declare
     * @param string|null                      $licence            the merged data set's licence; if not given, the one
     *                                                             that its input data sets share, if they state one
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $resultModel,
        public array $defining,
        public AbstractMergeByLocation $mergeByLocation,
        public array $conflictResolution = [],
        public array $augmenting = [],
        ?AbstractAugmentation $augmentation = null,
        public ?string $contextUrl = null,
        public ?string $licence = null,
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

        // The default merge by location groups records as they are, so their
        // attributes keep the names of their own model. A merge that reads
        // other models names a merge by location of its own, which restates
        // its records in the result model.
        $otherModels = array_diff($defining, [$resultModel]);
        if ([] !== $otherModels && OneToOneClosestFirst::class === $mergeByLocation::class) {
            throw new \InvalidArgumentException(\sprintf('Merge %s reads %s, which is not its result model %s, so it needs a merge by location of its own that restates those records in %s.', $id, implode(', ', $otherModels), $resultModel, $resultModel));
        }

        $this->augmentation = $augmentation ?? ([] === $augmenting ? null : new WithinAreaOrRadius($mergeByLocation->radius));
    }

    /**
     * The configuration a merge class declares.
     *
     * It is not necessary to make an instance to read it, as with the
     * definition of a source.
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
