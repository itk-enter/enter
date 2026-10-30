<?php

declare(strict_types=1);

namespace App\Skraafoto;

use App\SourceReader\FixedLifetimeCachingHttpClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the oblique aerial photos (skråfotos) of Dataforsyningen.
 *
 * The STAC API gives the photos as GeoJSON features. The footprint of a
 * photo is the area on the ground that it shows. The cogtiler makes a JPEG
 * thumbnail of a photo. Each request must have a Dataforsyningen token. The
 * scoped client "skraafoto.client" adds the token from APP_SKRAAFOTO_TOKEN.
 *
 * @see https://github.com/Klimadatastyrelsen/skraafoto_stac_public/blob/main/dokumentation.md
 */
final readonly class SkraafotoClient
{
    public const string STAC_URL = 'https://api.dataforsyningen.dk/rest/skraafoto_api/v1.0';

    public const string COGTILER_URL = 'https://api.dataforsyningen.dk/rest/skraafoto_cogtiler/v1.0';

    /**
     * The ids of the collections, one collection for each year of photos.
     */
    public const string COLLECTION_PATTERN = '/^skraafotos(\d{4})$/';

    /**
     * The directions of an oblique photo. A photo in the direction "nadir"
     * looks straight down.
     */
    private const array OBLIQUE = ['north', 'east', 'south', 'west'];

    /**
     * The number of photos that the API gives for one point. The newest
     * photos can look straight down, so the request asks for more than one.
     */
    private const int LIMIT = 5;

    /**
     * Half the side of the box that finds the photos of a point, in degrees.
     */
    private const float BOX = 1e-6;

    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $skraafotoClient,
        #[Autowire(service: 'source_reader.cache')]
        CacheInterface $cache,
    ) {
        $this->client = new FixedLifetimeCachingHttpClient($skraafotoClient, $cache);
    }

    /**
     * Gets the id of the collection with the most recent photos, for
     * example "skraafotos2023".
     */
    public function newestCollection(): string
    {
        $collections = $this->get(self::STAC_URL.'/collections')['collections'] ?? [];
        $ids = array_filter(
            array_column(\is_array($collections) ? $collections : [], 'id'),
            static fn (mixed $id): bool => \is_string($id) && 1 === preg_match(self::COLLECTION_PATTERN, $id),
        );
        rsort($ids, \SORT_STRING);

        return $ids[0] ?? throw new \RuntimeException('The Skråfoto API gives no collection of photos.');
    }

    /**
     * Finds the newest oblique photo whose footprint holds a point.
     *
     * The point can be near the edge of the footprint, so the photo does not
     * always show it clearly.
     *
     * @return array<string, mixed>|null the photo as a STAC item, or null if no oblique photo shows the point
     */
    public function photoAt(string $collection, float $longitude, float $latitude): ?array
    {
        $result = $this->get(\sprintf('%s/collections/%s/items', self::STAC_URL, rawurlencode($collection)), [
            'bbox' => implode(',', [$longitude - self::BOX, $latitude - self::BOX, $longitude + self::BOX, $latitude + self::BOX]),
            'sortby' => '-properties.datetime',
            'limit' => self::LIMIT,
        ]);

        foreach (\is_array($result['features'] ?? null) ? $result['features'] : [] as $photo) {
            if (\is_array($photo) && \in_array($photo['properties']['direction'] ?? null, self::OBLIQUE, true)) {
                return $photo;
            }
        }

        return null;
    }

    /**
     * Gets the JPEG thumbnail of a photo.
     *
     * The thumbnail is smaller than 512 pixels. The STAC item of the photo
     * gives the address of the thumbnail. This function reads the thumbnail
     * only from the cogtiler, so the token goes to no other host.
     */
    public function thumbnail(string $collection, string $photoId): string
    {
        $photo = $this->get(\sprintf('%s/collections/%s/items/%s', self::STAC_URL, rawurlencode($collection), rawurlencode($photoId)));
        $href = $photo['assets']['thumbnail']['href'] ?? null;
        if (!\is_string($href) || !str_starts_with($href, self::COGTILER_URL.'/')) {
            throw new SkraafotoNotFoundException(\sprintf('The photo %s in %s has no thumbnail.', $photoId, $collection));
        }

        try {
            return $this->client->request(Request::METHOD_GET, $href)->getContent();
        } catch (HttpExceptionInterface $exception) {
            throw self::failure($exception, $href);
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function get(string $url, array $query = []): array
    {
        try {
            return $this->client->request(Request::METHOD_GET, $url, ['query' => $query])->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw self::failure($exception, $url);
        }
    }

    /**
     * Makes an exception that tells why the API refused a request.
     */
    private static function failure(HttpExceptionInterface $exception, string $url): \RuntimeException
    {
        $status = $exception->getResponse()->getStatusCode();

        return match ($status) {
            Response::HTTP_NOT_FOUND => new SkraafotoNotFoundException(\sprintf('The Skråfoto API has no %s.', $url), previous: $exception),
            Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN => new \RuntimeException(\sprintf('The Skråfoto API refused the token with HTTP %d. Set APP_SKRAAFOTO_TOKEN to a valid Dataforsyningen token.', $status), previous: $exception),
            default => new \RuntimeException(\sprintf('The Skråfoto API refused %s with HTTP %d.', $url, $status), previous: $exception),
        };
    }
}
