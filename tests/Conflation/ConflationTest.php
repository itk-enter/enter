<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Conflation\Augmentation\WithinAreaOrRadius;
use App\Conflation\Conflation;
use App\Conflation\ConflationManager;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use App\Tests\Conflation\Fixtures\MostRestrictive;
use App\Tests\Conflation\Fixtures\OtherToiletConflation;
use App\Tests\Conflation\Fixtures\ToiletConflation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConflationTest extends TestCase
{
    public function testItReadsTheConfigurationAMergeClassDeclares(): void
    {
        $conflation = Conflation::of(ToiletConflation::class);

        $this->assertSame('merged-toilets', $conflation->id);
        $this->assertSame('ResultModel', $conflation->resultModel);
        $this->assertSame(['ModelA', 'ModelB'], $conflation->defining);
        $this->assertSame(['ModelC' => ['surface']], $conflation->augmenting);

        // The step implementations are built from the attribute, with the
        // arguments it gives them.
        $this->assertInstanceOf(GroupedByThing::class, $conflation->mergeByLocation);
        $this->assertSame(5.0, $conflation->mergeByLocation->radius);
        $this->assertEquals([new MostRestrictive(['wheelchair']), new SourceAuthority(['data-set-a'])], $conflation->conflictResolution);
        $this->assertInstanceOf(WithinAreaOrRadius::class, $conflation->augmentation);

        $this->assertTrue($conflation->isDefining('ModelB'));
        $this->assertFalse($conflation->isDefining('ModelC'));
        $this->assertTrue($conflation->isAugmenting('ModelC'));
    }

    public function testItRequiresTheAttribute(): void
    {
        $this->expectException(\LogicException::class);

        Conflation::of(self::class);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function testItRejectsAnInvalidConfiguration(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Conflation(...[
            'id' => 'merged',
            'title' => 'Merged toilets',
            'description' => 'Toilets merged from several data sets.',
            'resultModel' => 'ResultModel',
            'defining' => ['ModelA'],
            'mergeByLocation' => new GroupedByThing(5.0),
            'conflictResolution' => [new SourceAuthority(['data-set-a'])],
            ...$arguments,
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'no defining model' => [['defining' => []], 'names no defining model'];
        yield 'not an approach' => [['conflictResolution' => ['data-set-a']], 'must extend'];
        yield 'defining and augmenting' => [
            ['augmenting' => ['ModelA' => ['surface']], 'augmentation' => new WithinAreaOrRadius(5.0)],
            'both as defining and as augmenting',
        ];
    }

    public function testTheSimplestMergeNamesItsModelsAndHowToMatchThem(): void
    {
        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'ResultModel',
            defining: ['ModelA', 'ModelB'],
            mergeByLocation: new GroupedByThing(5.0),
        );

        $this->assertSame([], $conflation->conflictResolution);
        $this->assertSame([], $conflation->augmenting);
        $this->assertNull($conflation->augmentation);
    }

    public function testAMergeThatNamesAugmentingModelsGetsTheDefaultAugmentation(): void
    {
        $conflation = new Conflation(
            id: 'merged-parking',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'ResultModel',
            defining: ['ModelA', 'ModelB'],
            mergeByLocation: new GroupedByThing(5.0),
            augmenting: ['ModelC' => ['surface']],
        );

        $this->assertEquals(new WithinAreaOrRadius(5.0), $conflation->augmentation);
    }

    public function testTheDefaultMergeByLocationOnlyReadsTheResultModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a merge by location of its own');

        new Conflation(
            id: 'merged',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet', 'Toilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
        );
    }

    public function testAMergeOfOtherModelsNamesAMergeByLocationOfItsOwn(): void
    {
        $conflation = new Conflation(
            id: 'merged',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet', 'Toilet'],
            mergeByLocation: new GroupedByThing(radius: 10.0),
        );

        $this->assertSame(['PublicToilet', 'Toilet'], $conflation->defining);
    }

    public function testAStepRejectsARadiusThatIsNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GroupedByThing(0.0);
    }

    public function testTheManagerIndexesMergesById(): void
    {
        $manager = new ConflationManager([new ToiletConflation()]);

        $this->assertSame(['merged-toilets'], array_keys($manager->getConflations()));
        $this->assertSame('ResultModel', $manager->getConflation('merged-toilets')->resultModel);

        $this->expectException(\InvalidArgumentException::class);
        $manager->getConflation('merged-benches');
    }

    public function testTheManagerRejectsTwoMergesWithOneId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate merge: merged-toilets');

        new ConflationManager([new ToiletConflation(), new OtherToiletConflation()]);
    }
}
