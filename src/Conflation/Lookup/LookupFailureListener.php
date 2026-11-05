<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Logs a failed lookup on the log channel of the lookup.
 *
 * Messenger retries a failed message, as the transport of the lookup sets.
 * After the last retry, Messenger drops the message, because there is no
 * failure transport. The entity keeps the check property of the last good
 * check, so the next run sends the message again.
 */
#[AsEventListener]
#[WithMonologChannel(LookupLog::CHANNEL)]
final readonly class LookupFailureListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof LookupMessage) {
            return;
        }

        $config = AsLocationLookup::of($message->lookup);
        $exception = $event->getThrowable();
        if ($exception instanceof HandlerFailedException) {
            $exception = $exception->getPrevious() ?? $exception;
        }

        $context = ['lookup' => $config->id, 'entity' => $message->entityId, 'message' => $exception->getMessage(), 'exception' => $exception];
        $logger = LookupLog::for($this->logger, $config->id);
        if ($event->willRetry()) {
            $logger->warning('Lookup {lookup} failed for {entity}, and Messenger will try again: {message}', $context);
        } else {
            $logger->error('Lookup {lookup} failed for {entity} after all the retries. The next run sends the check again: {message}', $context);
        }
    }
}
