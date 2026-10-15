<?php

namespace App\GeometryHelper;

enum Crs: string
{
    // The one used by GeoJSON.
    case EPSG_4326 = 'EPSG:4326';
    case EPSG_25832 = 'EPSG:25832';

    public static function geoJson(): self
    {
        return self::EPSG_4326;
    }
}
