<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\Event\SourceImportStartedEvent;
use App\Import\Exception\ImportRunningException;
use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use App\SourceImporter\SourceImporterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Tests that only one process at a time imports a source. Two lock factories
 * on one store act as two processes.
 */
class SourcesImporterLockTest extends TestCase
{
    private const string LOCK = 'source-import-mtm_spatialmaps-toilet-city';

    private InMemoryStore $store;

    private int $started = 0;

    private int $imports = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
    }

    public function testItRefusesAnImportOfASourceThatAnotherProcessImports(): void
    {
        $other = new LockFactory($this->store)->createLock(self::LOCK, ttl: null);
        $this->assertTrue($other->acquire());

        try {
            $this->importer(failing: false)->importOne(new ToiletCity());
            $this->fail('Two processes imported one source at the same time.');
        } catch (ImportRunningException $exception) {
            $this->assertSame('mtm_spatialmaps-toilet-city', $exception->sourceId);
        }

        $this->assertSame(0, $this->started, 'A refused import must not reach the history.');
        $this->assertSame(0, $this->imports);
    }

    public function testItReleasesTheLockAfterTheImport(): void
    {
        $this->importer(failing: false)->importOne(new ToiletCity());

        $this->assertTrue(new LockFactory($this->store)->createLock(self::LOCK)->acquire());
    }

    public function testItReleasesTheLockAfterAFailedImport(): void
    {
        try {
            $this->importer(failing: true)->importOne(new ToiletCity());
        } catch (\RuntimeException) {
        }

        $this->assertTrue(new LockFactory($this->store)->createLock(self::LOCK)->acquire());
    }

    private function importer(bool $failing): SourcesImporter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SourceImportStartedEvent::class, function (): void {
            ++$this->started;
        });

        $fake = new readonly class($failing, function (): void {
            ++$this->imports;
        }) implements SourceImporterInterface {
            public function __construct(
                private bool $failing,
                private \Closure $onImport,
            ) {
            }

            public function supports(SourceInterface $source): bool
            {
                return true;
            }

            public function import(SourceInterface $source): ImportResult
            {
                ($this->onImport)();
                if ($this->failing) {
                    throw new \RuntimeException('Feed unreachable');
                }

                return new ImportResult(3, 0, 201, 'http://broker.example/', str_repeat('a', 64));
            }
        };

        return new SourcesImporter(new SourceImporterFactory([$fake]), $dispatcher, new LockFactory($this->store));
    }
}
