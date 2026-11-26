<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\LookupFailureListener;
use App\Conflation\Lookup\LookupMessage;
use App\Tests\Conflation\Fixtures\NearestAddress;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class LookupFailureListenerTest extends TestCase
{
    private TestHandler $log;

    private function listen(object $message, bool $willRetry): void
    {
        $this->log = new TestHandler();
        $envelope = new Envelope($message);
        $event = new WorkerMessageFailedEvent($envelope, 'lookups', new HandlerFailedException($envelope, [new \RuntimeException('The service is down.')]));
        if ($willRetry) {
            $event->setForRetry();
        }

        (new LookupFailureListener(new Logger('lookup', [$this->log])))($event);
    }

    private static function message(): LookupMessage
    {
        return new LookupMessage('urn:ngsi-ld:PublicToilet:t-1', 'https://context.example/toilet.jsonld', NearestAddress::class, ['address'], ['type' => 'Point', 'coordinates' => [10.21, 56.15]], null);
    }

    public function testAFailureBeforeTheLastRetryIsAWarningOnTheChannelOfTheLookup(): void
    {
        $this->listen(self::message(), true);

        $records = $this->log->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('lookup.addresses', $records[0]->channel);
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertSame('The service is down.', $records[0]->context['message']);
    }

    public function testAFailureAfterTheLastRetryIsAnError(): void
    {
        $this->listen(self::message(), false);

        $this->assertTrue($this->log->hasErrorThatContains('after all the retries'));
        $this->assertSame('lookup.addresses', $this->log->getRecords()[0]->channel);
    }

    public function testItIgnoresOtherMessages(): void
    {
        $this->listen(new \stdClass(), false);

        $this->assertSame([], $this->log->getRecords());
    }
}
