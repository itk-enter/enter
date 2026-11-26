<?php

declare(strict_types=1);

namespace App\Tests\Source;

use App\Source\Osm\Bench;
use PHPUnit\Framework\TestCase;

class AbstractSourceTest extends TestCase
{
    public function testTheJsonOfASourceIsItsDefinition(): void
    {
        $source = new Bench();

        $this->assertSame(json_encode($source->definition->toArray()), json_encode($source));
    }

    public function testASourceIsNamedByItsTitleAndId(): void
    {
        $this->assertSame('Bænke (OpenStreetMap), Aarhus Kommune (osm-bench)', (string) new Bench());
    }
}
