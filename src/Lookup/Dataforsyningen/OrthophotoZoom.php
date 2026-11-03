<?php

declare(strict_types=1);

namespace App\Lookup\Dataforsyningen;

/**
 * The zoom levels of an orthophoto. A higher level shows a smaller area.
 *
 * The photos have 10 to 12.5 cm on the ground for each pixel, so the WMS
 * shows no more detail than at level 4. The route accepts only these levels,
 * so that browsers can cache each image, and nobody can use the token for
 * images of any size.
 */
enum OrthophotoZoom: int
{
    /**
     * 320 × 240 metres.
     */
    case Wide = 1;

    /**
     * 160 × 120 metres.
     */
    case Medium = 2;

    /**
     * 80 × 60 metres: the place and the area around it.
     */
    case Default = 3;

    /**
     * 40 × 30 metres: the most detail that the photos have.
     */
    case Close = 4;

    /**
     * The metres on the ground for each pixel of the image.
     */
    public function metresPerPixel(): float
    {
        return match ($this) {
            self::Wide => 0.8,
            self::Medium => 0.4,
            self::Default => 0.2,
            self::Close => 0.1,
        };
    }
}
