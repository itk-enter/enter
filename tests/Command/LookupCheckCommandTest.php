<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\LookupCheckCommand;
use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupAvailabilityChecker;
use App\Tests\Conflation\Fixtures\NearestAddress;
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
        $application->addCommand(new LookupCheckCommand(new LookupAvailabilityChecker($lookups)));

        return new CommandTester($application->find('app:lookup:check'));
    }

    public function testItSucceedsIfAllServicesAreAvailable(): void
    {
        $tester = self::tester([new NearestAddress()]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('addresses', $tester->getDisplay());
        $this->assertStringContainsString('All 1 services are available.', $tester->getDisplay());
    }

    public function testItFailsIfAServiceFails(): void
    {
        $down = new #[AsLocationLookup(id: 'parking-zones', attributes: ['parkingZone'])] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \RuntimeException('The service is down.');
            }
        };
        $tester = self::tester([new NearestAddress(), $down]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('The service is down.', $tester->getDisplay());
        $this->assertStringContainsString('1 of 2 services failed.', $tester->getDisplay());
    }

    public function testThePointIsAnOption(): void
    {
        $tester = self::tester([new NearestAddress()]);

        $tester->execute(['--longitude' => '9.5', '--latitude' => '56.15']);

        // NearestAddress has no address west of longitude 10.
        $this->assertStringContainsString('No answer for the point', $tester->getDisplay());
    }
}
