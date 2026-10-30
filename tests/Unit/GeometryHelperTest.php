<?php

namespace App\Tests\Unit;

use App\GeometryHelper;
use PHPUnit\Framework\TestCase;
use proj4php\Point;

class GeometryHelperTest extends TestCase
{
    public function testTransformPoint(): void
    {
        $helper = new GeometryHelper();
        $input = new Point(
            576933.0181395365, 6218035.22691216,
        );

        $actual = $helper->transformPoint($input, GeometryHelper\Crs::EPSG_25832, GeometryHelper\Crs::geoJson());

        // https://epsg.io/transform#s_srs=25832&t_srs=4326&x=576933.0181395&y=6218035.2269122
        $this->assertEqualsWithDelta([10.236808, 56.101227], \array_slice($actual->toArray(), 0, 2), 1e-6);
    }
}
