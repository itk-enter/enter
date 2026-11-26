<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Ngsi\NgsiEntity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Keeps the answers of the lookups of a merge on its merged entities, and
 * requests the checks that are due.
 *
 * A run builds each merged entity again from the records. The answers of the
 * lookups are not in the records, so the run would publish the entity
 * without them. Thus, before the runner publishes, this class copies the
 * answers, and the check property of each lookup, from the entity that the
 * merge published before. It copies them only if the entity has the same id
 * and the same location. A merged entity that moves gets new answers.
 *
 * Before the runner publishes, this class also marks each check that is due
 * as requested. After the runner publishes, it sends a LookupMessage for each
 * marked check, to the transport of the lookup. A worker handles the message
 * later, with LookupHandler. While the mark is younger than the request
 * timeout of the lookup, a later run does not send the check again. Thus a
 * long queue gets no second message for one check.
 *
 * A lookup supplies only the attributes that the records of the thing do not
 * give. A value of the records always stays.
 */
final readonly class RemoteLookups
{
    public function __construct(
        private NgsiLdBroker $broker,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Copies the answers of the lookups from the published entities.
     *
     * The runner calls the method after it creates the merged entities, and
     * before it publishes them. If the merge names no lookups, the method
     * does not read the broker.
     *
     * @param list<NgsiEntity> $entities the merged entities of this run; the method adds the answers to them
     *
     * @return array<string, array<class-string, list<string>>> by entity id, and then by lookup class: the attributes
     *                                                          that the lookup must supply, because the records do not
     *                                                          give them
     */
    public function keepAnswers(Conflation $conflation, MergedDataSet $dataSet, array $entities): array
    {
        $wanted = [];
        foreach ($entities as $entity) {
            foreach ($conflation->lookups as $class => $attributes) {
                $missing = array_values(array_filter($attributes, static fn (string $attribute): bool => !$entity->has($attribute)));
                if ([] !== $missing) {
                    $wanted[$entity->id()][$class] = $missing;
                }
            }
        }

        if ([] === $wanted) {
            return [];
        }

        $previous = iterator_to_array($this->broker->entities($dataSet->id, $dataSet->contextUrl, self::attributes($conflation)));
        foreach ($entities as $entity) {
            $before = $previous[$entity->id()] ?? null;
            if (null === $before || !Location::same($entity->attribute('location')['value'] ?? null, $before['location']['value'] ?? null)) {
                continue;
            }

            foreach ($wanted[$entity->id()] ?? [] as $class => $attributes) {
                foreach ([...$attributes, AsLocationLookup::of($class)->checkProperty()] as $attribute) {
                    self::copy($entity, $attribute, $before[$attribute] ?? null);
                }
            }
        }

        return $wanted;
    }

    /**
     * Marks each check that is due as requested.
     *
     * The runner calls the method after keepAnswers(), and before it publishes
     * the merged entities. Thus the marks are in the entities that it
     * publishes. The method adds the sub-property requestedAt to the check
     * property. If the entity has no check property, the method adds one with
     * the value "pending".
     *
     * @param list<NgsiEntity>                                 $entities the merged entities, with the answers that
     *                                                                   keepAnswers() copied; the method adds the
     *                                                                   marks to them
     * @param array<string, array<class-string, list<string>>> $wanted   what keepAnswers() returned
     *
     * @return array<string, array<class-string, list<string>>> the checks that are due, in the same form as $wanted
     */
    public function markDueChecks(array $entities, array $wanted): array
    {
        $now = $this->clock->now();
        $requestedAt = $now->setTimezone(new \DateTimeZone('UTC'))->format(AsLocationLookup::TIME_FORMAT);
        $due = [];
        foreach ($entities as $entity) {
            foreach ($wanted[$entity->id()] ?? [] as $class => $attributes) {
                $lookup = AsLocationLookup::of($class);
                $check = $entity->attribute($lookup->checkProperty()) ?? ['type' => 'Property', 'value' => CheckResult::Pending->value];
                $checkedAt = \is_string($check['observedAt'] ?? null) ? $check['observedAt'] : null;
                $pending = $check[AsLocationLookup::REQUESTED_AT]['value'] ?? null;
                $pending = \is_string($pending) ? $pending : null;

                if (null === $entity->attribute('location') || !$lookup->isDue($checkedAt, array_any($attributes, $entity->has(...)), $now, $pending)) {
                    continue;
                }

                $check[AsLocationLookup::REQUESTED_AT] = ['type' => 'Property', 'value' => $requestedAt];
                $entity->setAttribute($lookup->checkProperty(), $check);
                $due[$entity->id()][$class] = $attributes;
            }
        }

        return $due;
    }

    /**
     * Sends a message for each check that markDueChecks() marked.
     *
     * The runner calls the method after it publishes the merged entities, so
     * that the worker finds the entities in the broker.
     *
     * @param list<NgsiEntity>                                 $entities the merged entities that the runner published
     * @param array<string, array<class-string, list<string>>> $due      what markDueChecks() returned
     *
     * @return int the number of messages sent
     */
    public function requestChecks(MergedDataSet $dataSet, array $entities, array $due): int
    {
        $sent = 0;
        foreach ($entities as $entity) {
            foreach ($due[$entity->id()] ?? [] as $class => $attributes) {
                $lookup = AsLocationLookup::of($class);
                $checkedAt = $entity->attribute($lookup->checkProperty())['observedAt'] ?? null;
                $checkedAt = \is_string($checkedAt) ? $checkedAt : null;

                /** @var array<string, mixed> $location */
                $location = $entity->attribute('location')['value'] ?? [];

                $this->bus->dispatch(
                    new LookupMessage($entity->id(), $dataSet->contextUrl, $class, $attributes, $location, $checkedAt),
                    [new TransportNamesStamp([$lookup->transport])],
                );
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * The attributes to read from the published entities: the location, and
     * the attributes and the check property of each lookup.
     *
     * @return list<string>
     */
    private static function attributes(Conflation $conflation): array
    {
        $attributes = ['location'];
        foreach ($conflation->lookups as $class => $supplied) {
            $attributes = [...$attributes, ...$supplied, AsLocationLookup::of($class)->checkProperty()];
        }

        return $attributes;
    }

    /**
     * Copies an attribute as the broker gave it, with its observedAt and its
     * sub-properties, for example the request mark of a check property.
     */
    private static function copy(NgsiEntity $entity, string $name, mixed $attribute): void
    {
        if (!\is_array($attribute) || 'Property' !== ($attribute['type'] ?? null) || !\array_key_exists('value', $attribute)) {
            return;
        }

        /* @var array<string, mixed> $attribute */
        $entity->setAttribute($name, $attribute);
    }
}
