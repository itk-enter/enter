<?php

namespace App\Source;

enum DataType: string
{
    case GeoJSON = 'geojson';
    case Overpass = 'overpass';
    case FindToilet = 'findtoilet';
}
