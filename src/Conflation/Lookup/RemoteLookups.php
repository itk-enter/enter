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
 * After the runner publishes, this class sends a LookupMessage for each
 * merged entity and lookup that is due for a check, to the transport of the
 * lookup. A worker handles the message later, with LookupHandler.
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
     * Sends a message for each merged entity and lookup that is due for a
     * check.
     *
     * The runner calls the method after it publishes the merged entities.
     *
     * @param list<NgsiEntity>                                 $entities the merged entities, with the answers that
     *                                                                   keepAnswers() copied
     * @param array<string, array<class-string, list<string>>> $wanted   what keepAnswers() returned
     *
     * @return int the number of messages sent
     */
    public function requestChecks(MergedDataSet $dataSet, array $entities, array $wanted): int
    {
        $now = $this->clock->now();
        $sent = 0;
        foreach ($entities as $entity) {
            foreach ($wanted[$entity->id()] ?? [] as $class => $attributes) {
                $lookup = AsLocationLookup::of($class);
                $checkedAt = $entity->attribute($lookup->checkProperty())['observedAt'] ?? null;
                $checkedAt = \is_string($checkedAt) ? $checkedAt : null;
                $hasValue = array_any($attributes, $entity->has(...));

                /** @var array<string, mixed> $location */
                $location = $entity->attribute('location')['value'] ?? [];
                if ([] === $location || !$lookup->isDue($checkedAt, $hasValue, $now)) {
                    continue;
                }

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

    private static function copy(NgsiEntity $entity, string $name, mixed $attribute): void
    {
        if (!\is_array($attribute) || 'Property' !== ($attribute['type'] ?? null) || !\array_key_exists('value', $attribute)) {
            return;
        }

        $observedAt = $attribute['observedAt'] ?? null;
        $entity->setProperty($name, $attribute['value'], \is_string($observedAt) ? $observedAt : null);
    }
}
