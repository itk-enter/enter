<?php

namespace App\GeometryHelper;

enum Crs: string
{
    // GeoJSON uses this CRS.
    case EPSG_4326 = 'EPSG:4326';
    case EPSG_25832 = 'EPSG:25832';

    public static function geoJson(): self
    {
        return self::EPSG_4326;
    }
}
