<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * Runs the customizable steps of a merge on its records, and checks that
 * what each step returns stays within the step's limits.
 *
 * Merging by location groups the records of defining models, resolving
 * conflicts turns each group into one merged entity, and augmenting adds
 * attributes from the records of augmenting models. A step that breaks its
 * limits fails the run with a ConflationLimitException, so nothing is
 * published.
 *
 * Fetching the records and creating the result in the broker are not
 * customizable and happen around this.
 */
final class ConflationPipeline
{
    /**
     * @param list<Record> $records every record of the models the merge names
     *
     * @return list<MergedEntity> ordered by id
     */
    public function run(Conflation $conflation, array $records): array
    {
        $defining = [];
        $augmenting = [];
        // Split the records by the role the merge gives their model.
        foreach ($records as $record) {
            if ($conflation->isDefining($record->model)) {
                $defining[] = $record;
            } elseif ($conflation->isAugmenting($record->model)) {
                $augmenting[] = $record;
            } else {
                throw new \InvalidArgumentException(\sprintf('Record %s is of model "%s", which merge %s does not name.', $record->id, $record->model, $conflation->id));
            }
        }

        $clusters = $conflation->mergeByLocation->merge($defining);
        $this->checkGroups($conflation, $defining, $clusters);

        $resolver = new ConflictResolver($conflation->conflictResolution);
        $entities = array_map($resolver->resolve(...), $clusters);
        usort($entities, static fn (MergedEntity $x, MergedEntity $y): int => strcmp($x->id, $y->id));

        if (null !== $conflation->augmentation && [] !== $augmenting) {
            $supplied = $conflation->augmentation->augment($entities, $augmenting, $conflation->augmenting);
            $this->checkAugmentation($conflation, $entities, $augmenting, $supplied);
            $entities = array_map(
                fn (MergedEntity $entity): MergedEntity => $this->augmented($entity, $supplied[$entity->id] ?? [], $resolver),
                $entities,
            );
        }

        return $entities;
    }

    /**
     * Every defining record in exactly one group, nothing else in any, and
     * at most one record per data set in a group.
     *
     * @param list<Record>  $records
     * @param list<Cluster> $clusters
     */
    private function checkGroups(Conflation $conflation, array $records, array $clusters): void
    {
        $expected = array_fill_keys(array_map(static fn (Record $record): string => $record->id, $records), 0);
        $given = array_column($records, null, 'id');

        // Count the groups each record is in, and the data sets each group holds.
        foreach ($clusters as $cluster) {
            $sourceIds = [];
            foreach ($cluster->records as $record) {
                if (!isset($expected[$record->id])) {
                    throw new ConflationLimitException(\sprintf('Merge %s grouped record %s, which is not a record of its defining models.', $conflation->id, $record->id));
                }
                ++$expected[$record->id];

                $original = $given[$record->id];
                if ($record->sourceId !== $original->sourceId || $record->location() !== $original->location()) {
                    throw new ConflationLimitException(\sprintf('Merge %s changed the data set or the location of record %s.', $conflation->id, $record->id));
                }

                if (isset($sourceIds[$record->sourceId])) {
                    throw new ConflationLimitException(\sprintf('Merge %s grouped %s and %s, two records of data set %s.', $conflation->id, $sourceIds[$record->sourceId], $record->id, $record->sourceId));
                }
                $sourceIds[$record->sourceId] = $record->id;
            }
        }

        foreach ($expected as $id => $count) {
            if (1 !== $count) {
                throw new ConflationLimitException(\sprintf('Merge %s put record %s in %d groups, not one.', $conflation->id, $id, $count));
            }
        }
    }

    /**
     * Values only for entities that exist, from the augmenting records
     * given, for attributes the merge names for the record's model, and for
     * attributes the entity lacks.
     *
     * @param list<MergedEntity>                              $entities
     * @param list<Record>                                    $records
     * @param array<string, array<string, list<StatedValue>>> $supplied
     */
    private function checkAugmentation(Conflation $conflation, array $entities, array $records, array $supplied): void
    {
        $entitiesById = array_column($entities, null, 'id');
        $modelByRecord = array_column($records, 'model', 'id');

        foreach ($supplied as $entityId => $attributes) {
            $entity = $entitiesById[$entityId] ?? throw new ConflationLimitException(\sprintf('Merge %s augmented %s, which is not one of its merged entities.', $conflation->id, $entityId));

            foreach ($attributes as $attribute => $values) {
                if ($entity->has($attribute)) {
                    throw new ConflationLimitException(\sprintf('Merge %s augmented %s with %s, which its members already state.', $conflation->id, $entityId, $attribute));
                }

                foreach ($values as $value) {
                    $model = $modelByRecord[$value->recordId] ?? throw new ConflationLimitException(\sprintf('Merge %s augmented %s from %s, which is not one of its augmenting records.', $conflation->id, $entityId, $value->recordId));
                    if (!\in_array($attribute, $conflation->augmenting[$model], true)) {
                        throw new ConflationLimitException(\sprintf('Merge %s augmented %s with %s from model %s, which it does not name for that model.', $conflation->id, $entityId, $attribute, $model));
                    }
                }
            }
        }
    }

    /**
     * @param array<string, list<StatedValue>> $supplied
     */
    private function augmented(MergedEntity $entity, array $supplied, ConflictResolver $resolver): MergedEntity
    {
        if ([] === $supplied) {
            return $entity;
        }

        $attributes = [];
        $conflicts = [];
        $recordIds = [];
        ksort($supplied, \SORT_STRING);
        foreach ($supplied as $attribute => $values) {
            if ([] === $values) {
                continue;
            }

            // Several records can supply one attribute, such as overlapping
            // areas; the merge's approaches settle them as any other conflict.
            usort($values, static fn (StatedValue $x, StatedValue $y): int => [$x->sourceId, $x->recordId] <=> [$y->sourceId, $y->recordId]);
            [$settled, $value] = $resolver->settle($attribute, $values);
            if ($settled) {
                $attributes[$attribute] = $value;
            } else {
                $conflicts[$attribute] = $values;
            }
            array_push($recordIds, ...array_map(static fn (StatedValue $value): string => $value->recordId, $values));
        }

        return $entity->augmentedWith($attributes, $conflicts, $recordIds);
    }
}
