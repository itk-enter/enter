<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lookup\Dataforsyningen\OrthophotoClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sends an orthophoto of a point, with the token kept on the server.
 */
final class OrthophotoController extends AbstractController
{
    /**
     * The time, in seconds, that a browser or a shared cache can keep an
     * image: 7 days.
     *
     * In this time, a request for the same image does not reach this app or
     * the WMS, so a page with many images does not use the token again and
     * again. The image of a point changes only when Dataforsyningen publishes
     * a new orthophoto, approximately once a year.
     */
    private const int MAX_AGE = 604800;

    /**
     * Sends the orthophoto of a point.
     *
     * The point is in UTM 32 (EPSG:25832), in whole metres. In Denmark, the
     * easting x has 6 digits and the northing y has 7 digits, so the route
     * accepts only these formats. Other values, for example letters, decimals
     * or negative numbers, do not match this route, so the WMS gets no request
     * for them. If the WMS gives no image, the method gives 404.
     */
    #[Route(
        path: '/orthophoto/{x},{y}.jpg',
        name: 'app_orthophoto',
        requirements: ['x' => '\d{6}', 'y' => '\d{7}'],
        methods: [Request::METHOD_GET],
    )]
    public function image(OrthophotoClient $client, int $x, int $y): Response
    {
        $jpeg = $client->image($x, $y);
        if (null === $jpeg) {
            throw new NotFoundHttpException('The WMS gave no image of the point.');
        }

        return new Response($jpeg, Response::HTTP_OK, ['Content-Type' => 'image/jpeg'])
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
