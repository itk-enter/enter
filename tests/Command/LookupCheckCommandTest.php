<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\LookupCheckCommand;
use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupProbe;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class LookupCheckCommandTest extends TestCase
{
    /**
     * @param list<LocationLookupInterface> $lookups
     */
    private static function tester(array $lookups): CommandTester
    {
        $application = new Application();
        $application->addCommand(new LookupCheckCommand(new LookupProbe($lookups)));

        return new CommandTester($application->find('app:lookup:check'));
    }

    public function testItSucceedsIfAllServicesAreAvailable(): void
    {
        $tester = self::tester([new AerialPhotos()]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('aerial-photos', $tester->getDisplay());
        $this->assertStringContainsString('All 1 services are available.', $tester->getDisplay());
    }

    public function testItFailsIfAServiceFails(): void
    {
        $down = new #[AsLocationLookup(id: 'addresses', attributes: ['address'], recheckEmptyAfter: 'P7D', refreshAfter: 'P90D')] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \RuntimeException('The service is down.');
            }
        };
        $tester = self::tester([new AerialPhotos(), $down]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('The service is down.', $tester->getDisplay());
        $this->assertStringContainsString('1 of 2 services failed.', $tester->getDisplay());
    }

    public function testThePointIsAnOption(): void
    {
        $tester = self::tester([new AerialPhotos()]);

        $tester->execute(['--longitude' => '9.5', '--latitude' => '56.15']);

        // AerialPhotos has no photo west of longitude 10.
        $this->assertStringContainsString('No answer for the point', $tester->getDisplay());
    }
}
