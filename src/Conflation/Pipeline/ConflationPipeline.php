<?php

declare(strict_types=1);

namespace App\Conflation\Pipeline;

use App\Conflation\Conflation;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Spatial\Shape;

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
     * Compares records before and after a merge-by-location run, ensuring
     * that every defining record is in exactly one group, unchanged or
     * restated, and that no group holds two records of one data set.
     *
     * @param list<Record>  $records  the defining records, before
     * @param list<Cluster> $clusters the groups, after
     */
    private function checkGroups(Conflation $conflation, array $records, array $clusters): void
    {
        $expected = array_fill_keys(array_map(static fn (Record $record): string => $record->id, $records), 0);
        $given = array_column($records, null, 'id');
        /** @var array<string, string> $restated input record id by restated record id */
        $restated = [];

        // Count the groups each record is in, and the data sets each group holds.
        foreach ($clusters as $cluster) {
            $sourceIds = [];
            foreach ($cluster->records as $record) {
                if (null === $record->restatedFrom) {
                    $this->checkGivenRecord($conflation, $record, $given);
                    ++$expected[$record->id];
                } else {
                    $this->checkRestatedRecord($conflation, $record, $given, $restated);
                    $restated[$record->id] = $record->restatedFrom;
                }

                if (isset($sourceIds[$record->sourceId])) {
                    throw new ConflationLimitException(\sprintf('Merge %s grouped %s and %s, two records of data set %s.', $conflation->id, $sourceIds[$record->sourceId], $record->id, $record->sourceId));
                }
                $sourceIds[$record->sourceId] = $record->id;
            }
        }

        $restatedInputs = array_flip($restated);
        foreach ($expected as $id => $count) {
            if (isset($restatedInputs[$id])) {
                if (0 !== $count) {
                    throw new ConflationLimitException(\sprintf('Merge %s grouped record %s both as itself and restated.', $conflation->id, $id));
                }
                continue;
            }

            if (1 !== $count) {
                throw new ConflationLimitException(\sprintf('Merge %s put record %s in %d groups, not one.', $conflation->id, $id, $count));
            }
        }
    }

    /**
     * Ensures that an unchanged record is a defining record, with the same
     * data set and location.
     *
     * @param array<string, Record> $given the defining records, by id
     */
    private function checkGivenRecord(Conflation $conflation, Record $record, array $given): void
    {
        // The record must be a defining record.
        $original = $given[$record->id] ?? throw new ConflationLimitException(\sprintf('Merge %s grouped record %s, which is not a record of its defining models.', $conflation->id, $record->id));

        // The record must keep its data set and its location.
        if ($record->sourceId !== $original->sourceId || $record->location() !== $original->location()) {
            throw new ConflationLimitException(\sprintf('Merge %s changed the data set or the location of record %s.', $conflation->id, $record->id));
        }
    }

    /**
     * Ensures that a restated record has a new id, the result model and the
     * data set of its defining record, and lies within the radius of it.
     *
     * @param array<string, Record> $given    the defining records, by id
     * @param array<string, string> $restated the restated records found so far, by id
     */
    private function checkRestatedRecord(Conflation $conflation, Record $record, array $given, array $restated): void
    {
        // The record it is restated from must be a defining record.
        $original = $given[(string) $record->restatedFrom] ?? throw new ConflationLimitException(\sprintf('Merge %s grouped record %s, restated from %s, which is not a record of its defining models.', $conflation->id, $record->id, $record->restatedFrom));

        // The record must not be the id of a defining record or of another restated record.
        if (isset($given[$record->id]) || isset($restated[$record->id])) {
            throw new ConflationLimitException(\sprintf('Merge %s restated record %s under the id %s, which another record already has.', $conflation->id, $record->restatedFrom, $record->id));
        }

        // The record must be in the result model.
        if ($record->model !== $conflation->resultModel) {
            throw new ConflationLimitException(\sprintf('Merge %s restated record %s in model %s, not in its result model %s.', $conflation->id, $record->id, $record->model, $conflation->resultModel));
        }

        // The record must keep the data set of the record it is restated from.
        if ($record->sourceId !== $original->sourceId) {
            throw new ConflationLimitException(\sprintf('Merge %s changed the data set of record %s, restated from %s.', $conflation->id, $record->id, $original->id));
        }

        // The record must lie within the radius of the record it is restated from.
        if (Shape::distance($record, $original->location()) > $conflation->mergeByLocation->radius) {
            throw new ConflationLimitException(\sprintf('Merge %s put record %s further than %s m from %s, which it is restated from.', $conflation->id, $record->id, $conflation->mergeByLocation->radius, $original->id));
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
