<?php

declare(strict_types=1);

namespace App\Lookup\Dataforsyningen;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gets an orthophoto of a point in Denmark from the WMS of Dataforsyningen.
 *
 * An orthophoto shows the ground from straight above. The WMS makes the
 * image, so the application does not make or store images. The scoped client
 * "dataforsyningen.client" adds the token from DATAFORSYNINGEN_TOKEN.
 *
 * @see https://docs.dataforsyningen.dk/
 */
final readonly class OrthophotoClient
{
    public const string WMS_URL = 'https://api.dataforsyningen.dk/orto_foraar_DAF';

    /**
     * The newest spring orthophoto of Denmark.
     */
    public const string LAYER = 'orto_foraar';

    public const int WIDTH = 400;

    public const int HEIGHT = 300;

    /**
     * The metres on the ground for each pixel. 400 × 300 pixels show 80 × 60
     * metres around the point.
     */
    private const float METRES_PER_PIXEL = 0.2;

    public function __construct(
        private HttpClientInterface $dataforsyningenClient,
    ) {
    }

    /**
     * Gets a JPEG image of the area around a point.
     *
     * @param int $x the easting of the point in UTM 32, in metres
     * @param int $y the northing of the point in UTM 32, in metres
     *
     * @return string|null the JPEG image, or null if the WMS gives no image
     */
    public function image(int $x, int $y): ?string
    {
        $halfWidth = self::WIDTH * self::METRES_PER_PIXEL / 2;
        $halfHeight = self::HEIGHT * self::METRES_PER_PIXEL / 2;
        $response = $this->dataforsyningenClient->request(Request::METHOD_GET, self::WMS_URL, ['query' => [
            'service' => 'WMS',
            'version' => '1.3.0',
            'request' => 'GetMap',
            'layers' => self::LAYER,
            'styles' => '',
            'crs' => 'EPSG:25832',
            'bbox' => implode(',', [$x - $halfWidth, $y - $halfHeight, $x + $halfWidth, $y + $halfHeight]),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'format' => 'image/jpeg',
        ]]);

        // A WMS reports an error as XML, often with HTTP 200.
        $type = $response->getHeaders(false)['content-type'][0] ?? '';
        if (200 !== $response->getStatusCode() || !str_starts_with($type, 'image/jpeg')) {
            return null;
        }

        return $response->getContent();
    }
}
