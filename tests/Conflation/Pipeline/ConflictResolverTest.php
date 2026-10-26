<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Pipeline;

use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Pipeline\ConflationLimitException;
use App\Conflation\Pipeline\ConflictResolver;
use App\Tests\Conflation\Fixtures\MostRestrictive;
use PHPUnit\Framework\TestCase;

class ConflictResolverTest extends TestCase
{
    /**
     * Three records of one toilet that disagree on opening hours and
     * wheelchair access.
     *
     * @return list<Record>
     */
    private static function toilet(): array
    {
        return [
            new Record('A-17', 'data-set-a', 10.2100, 56.1500, 'ModelA', ['openingHours' => '08–20', 'wheelchair' => 'yes', 'fee' => 'free']),
            new Record('B-903', 'data-set-b', 10.2101, 56.1500, 'ModelA', ['openingHours' => '06–22', 'wheelchair' => 'no']),
            new Record('C-4', 'data-set-c', 10.2100, 56.1501, 'ModelB', ['openingHours' => '08–20', 'wheelchair' => 'yes', 'fee' => 'free']),
        ];
    }

    public function testItSettlesConflictsByTheApproachesInOrder(): void
    {
        $entity = new ConflictResolver([new SourceAuthority(['data-set-a'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame(['fee' => 'free', 'openingHours' => '08–20', 'wheelchair' => 'yes'], $entity->attributes);
        $this->assertSame([], $entity->conflicts);

        // A customization listed first settles the attributes it concerns,
        // and leaves the rest to the next approach.
        $entity = new ConflictResolver([new MostRestrictive(['wheelchair']), new SourceAuthority(['data-set-a'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame(['fee' => 'free', 'openingHours' => '08–20', 'wheelchair' => 'no'], $entity->attributes);
    }

    public function testAnAbsentValueIsNoConflict(): void
    {
        // Data set B states no fee and agrees on everything else, so no
        // approach is asked.
        $entity = new ConflictResolver([self::refusingApproach()])->resolve(new Cluster([
            new Record('A-17', 'data-set-a', 10.2100, 56.1500, 'ModelA', ['openingHours' => '08–20', 'fee' => 'free']),
            new Record('B-903', 'data-set-b', 10.2100, 56.1500, 'ModelA', ['openingHours' => '08–20']),
        ]));

        $this->assertSame(['fee' => 'free', 'openingHours' => '08–20'], $entity->attributes);
        $this->assertSame([], $entity->conflicts);
    }

    public function testItRecordsAConflictNoApproachSettles(): void
    {
        $entity = new ConflictResolver([new SourceAuthority(['data-set-d'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame(['fee' => 'free'], $entity->attributes);
        $this->assertEquals(
            [
                new StatedValue('08–20', 'A-17', 'data-set-a'),
                new StatedValue('06–22', 'B-903', 'data-set-b'),
                new StatedValue('08–20', 'C-4', 'data-set-c'),
            ],
            $entity->conflicts['openingHours'],
        );
        $this->assertArrayHasKey('wheelchair', $entity->conflicts);
        $this->assertTrue($entity->has('wheelchair'));
    }

    public function testItTakesIdentityAndProvenanceFromTheMembers(): void
    {
        $entity = new ConflictResolver([new SourceAuthority(['data-set-a'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame('A-17', $entity->id);
        $this->assertSame(['A-17', 'B-903', 'C-4'], $entity->provenance);
    }

    public function testItSettlesTheLocationLikeAnyOtherAttribute(): void
    {
        $entity = new ConflictResolver([new SourceAuthority(['data-set-c'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame(['type' => 'Point', 'coordinates' => [10.2100, 56.1501]], $entity->location);
    }

    public function testTheLocationFallsBackToTheFirstSortingMember(): void
    {
        $entity = new ConflictResolver([new SourceAuthority(['data-set-d'])])->resolve(new Cluster(self::toilet()));

        $this->assertSame(['type' => 'Point', 'coordinates' => [10.2100, 56.1500]], $entity->location);
        $this->assertArrayNotHasKey('location', $entity->conflicts);
    }

    public function testTheLocationMustBeSettledAsAGeometry(): void
    {
        $approach = new class extends AbstractConflictResolution {
            public function resolve(string $attribute, array $values): mixed
            {
                return 'somewhere';
            }
        };

        $this->expectException(ConflationLimitException::class);

        new ConflictResolver([$approach])->resolve(new Cluster(self::toilet()));
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheMembers(): void
    {
        $resolver = new ConflictResolver([new SourceAuthority(['data-set-d'])]);

        $this->assertEquals(
            $resolver->resolve(new Cluster(self::toilet())),
            $resolver->resolve(new Cluster(array_reverse(self::toilet()))),
        );
    }

    private static function refusingApproach(): AbstractConflictResolution
    {
        return new class extends AbstractConflictResolution {
            public function resolve(string $attribute, array $values): mixed
            {
                throw new \LogicException(\sprintf('No approach should be asked about %s.', $attribute));
            }
        };
    }
}
