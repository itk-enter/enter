<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asks a lookup about a merged entity, and writes the answer to the entity
 * in the broker.
 *
 * The handler writes only the attributes of the lookup and its check
 * property, with a partial update. It never writes the full entity, so it
 * does not change values that the merge run writes.
 *
 * The handler ignores a message, and writes nothing, if:
 *
 * - the entity does not exist any more,
 * - the entity has moved since the run sent the message, or
 * - another message for the same check was handled first.
 *
 * If the lookup has no answer, the handler writes only the check property.
 * The entity keeps the values that it has, because a service with no answer
 * can be down or have a defect. If the lookup throws an exception, the
 * handler writes nothing, and Messenger tries the message again.
 *
 * The handler logs on the log channel of the lookup.
 */
#[AsMessageHandler]
#[WithMonologChannel(LookupLog::CHANNEL)]
final readonly class LookupHandler
{
    public function __construct(
        #[AutowireLocator('app.location_lookup')]
        private ContainerInterface $lookups,
        private NgsiLdBroker $broker,
        private Location $location,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LookupMessage $message): void
    {
        $config = AsLocationLookup::of($message->lookup);
        $checkProperty = $config->checkProperty();
        $logger = LookupLog::for($this->logger, $config->id);

        $entity = $this->broker->entity($message->entityId, $message->contextUrl, ['location', $checkProperty]);
        if (null === $entity) {
            $logger->info('Skipped lookup {lookup} for {entity}: the entity does not exist.', ['lookup' => $config->id, 'entity' => $message->entityId]);

            return;
        }

        if (!Location::same($entity['location']['value'] ?? null, $message->location)) {
            $logger->info('Skipped lookup {lookup} for {entity}: the entity has moved.', ['lookup' => $config->id, 'entity' => $message->entityId]);

            return;
        }

        $checkedAt = $entity[$checkProperty]['observedAt'] ?? null;
        if ($checkedAt !== $message->checkedAt) {
            $logger->info('Skipped lookup {lookup} for {entity}: another message did the check.', ['lookup' => $config->id, 'entity' => $message->entityId]);

            return;
        }

        /** @var LocationLookupInterface $lookup */
        $lookup = $this->lookups->get($message->lookup);
        [$longitude, $latitude] = $this->location->point($message->location);
        $answer = $lookup->lookup($longitude, $latitude) ?? [];

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $attributes = [];
        foreach ($message->attributes as $name) {
            $value = $answer[$name] ?? null;
            if (null !== $value && '' !== $value && [] !== $value) {
                $attributes[$name] = ['type' => 'Property', 'value' => $value, 'observedAt' => $now];
            }
        }
        $result = [] === $attributes ? CheckResult::None : CheckResult::Found;
        $attributes[$checkProperty] = ['type' => 'Property', 'value' => $result->value, 'observedAt' => $now];

        if (!$this->broker->appendAttributes($message->entityId, $message->contextUrl, $attributes)) {
            $logger->info('Skipped lookup {lookup} for {entity}: the entity does not exist.', ['lookup' => $config->id, 'entity' => $message->entityId]);

            return;
        }

        $logger->debug('Lookup {lookup} wrote "{result}" to {entity}.', ['lookup' => $config->id, 'result' => $result->value, 'entity' => $message->entityId]);
    }
}
