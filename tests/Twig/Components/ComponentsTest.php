<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Import\History\ImportStatus;
use App\Import\History\ImportSummary;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * Tests the variants and the special cases of the Twig components in templates/components/. The pages test the usual
 * cases.
 */
class ComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testAHeadingHasTheLookOfItsSize(): void
    {
        $heading = $this->renderTwigComponent('Heading', ['level' => 2, 'size' => 'md'], 'Imports')->crawler()->filter('h2');

        $this->assertSame('h4', $heading->attr('class'));
        $this->assertSame('Imports', $heading->text());
    }

    public function testAHeadingWithoutASizeHasOnlyItsOwnClasses(): void
    {
        $heading = $this->renderTwigComponent('Heading', ['level' => 3, 'class' => 'visually-hidden'], 'Title')->crawler()->filter('h3');

        $this->assertSame('visually-hidden', $heading->attr('class'));
    }

    public function testAButtonWithAnAddressIsALink(): void
    {
        $link = $this->renderTwigComponent('Button', ['variant' => 'secondary', 'size' => 'sm', 'href' => '/sources'], 'Sources')->crawler()->filter('a');

        $this->assertSame('/sources', $link->attr('href'));
        $this->assertSame('itkaarhus-btn itkaarhus-btn--secondary itkaarhus-btn--sm', $link->attr('class'));
    }

    public function testAButtonWithoutAnAddressIsAButton(): void
    {
        $button = $this->renderTwigComponent('Button', ['type' => 'button'], 'Open')->crawler()->filter('button');

        $this->assertSame('button', $button->attr('type'));
        $this->assertSame('itkaarhus-btn itkaarhus-btn--primary', $button->attr('class'));
        $this->assertSame('Open', $button->text());
    }

    public function testATagCanHaveADot(): void
    {
        $tag = $this->renderTwigComponent('Tag', ['variant' => 'danger', 'dot' => true], 'Failed')->crawler()->filter('span');

        $this->assertSame('itkaarhus-tag itkaarhus-tag--danger itkaarhus-tag--dot', $tag->attr('class'));
    }

    public function testTheStatusOfAFailedImportShowsTheFailure(): void
    {
        $tag = $this->renderTwigComponent('Import:Status', ['import' => $this->import(ImportStatus::Failed, 'Timeout')])->crawler()->filter('span');

        $this->assertSame('Fejlet', $tag->text());
        $this->assertSame('Timeout', $tag->attr('title'));
        $this->assertStringContainsString('itkaarhus-tag--danger', (string) $tag->attr('class'));
    }

    public function testTheStatusOfASuccessfulImportHasNoTitle(): void
    {
        $tag = $this->renderTwigComponent('Import:Status', ['import' => $this->import(ImportStatus::Succeeded)])->crawler()->filter('span');

        $this->assertNull($tag->attr('title'));
        $this->assertStringContainsString('itkaarhus-tag--success', (string) $tag->attr('class'));
    }

    public function testAChangeHasASignAndAColour(): void
    {
        $change = $this->renderTwigComponent('Import:Change', ['count' => 1234, 'sign' => '+', 'variant' => 'added'])->crawler()->filter('span');

        $this->assertSame('+1.234', $change->text());
        $this->assertSame('import-change import-change-added', $change->attr('class'));
    }

    public function testZeroChangesHaveNoSignAndNoColour(): void
    {
        $change = $this->renderTwigComponent('Import:Change', ['count' => 0, 'sign' => '−', 'variant' => 'removed'])->crawler()->filter('span');

        $this->assertSame('0', $change->text());
        $this->assertSame('import-change import-change-zero', $change->attr('class'));
    }

    public function testATimeWithoutADateIsEmpty(): void
    {
        $this->assertSame('', trim($this->renderTwigComponent('Time', ['date' => null])->toString()));
    }

    public function testAModelWithoutAnAddressIsNotALink(): void
    {
        $crawler = $this->renderTwigComponent('Source:ModelLink', ['model' => 'Bench'])->crawler();

        $this->assertCount(0, $crawler->filter('a'));
        $this->assertSame('Bench', $crawler->filter('code')->text());
    }

    public function testAnErrorIsAnAlertAndTheOtherMessagesAreAStatus(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('error', 'The import failed.');
        $session->getFlashBag()->add('success', 'The import started.');
        $session->getFlashBag()->add('notice', 'An unknown type.');
        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get(RequestStack::class)->push($request);

        $messages = $this->renderTwigComponent('Layout:Flashes')->crawler()->filter('.alert');

        $this->assertSame(['alert', 'status', 'status'], $messages->each(static fn ($message): ?string => $message->attr('role')));
        $this->assertSame('alert alert-danger', $messages->eq(0)->attr('class'));
        $this->assertSame('alert alert-info', $messages->eq(2)->attr('class'));
        $this->assertSame('The import failed.', $messages->eq(0)->text());
    }

    private function import(ImportStatus $status, ?string $failure = null): ImportSummary
    {
        $at = new \DateTimeImmutable('2026-11-20 01:00 UTC');

        return new ImportSummary(1, 'osm-bench', $at, $at, $status, null, null, null, null, 0, $failure);
    }
}
