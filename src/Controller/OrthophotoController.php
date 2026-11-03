<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lookup\Dataforsyningen\OrthophotoClient;
use App\Lookup\Dataforsyningen\OrthophotoZoom;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sends an orthophoto of a point in Denmark, with the token kept on the server.
 * The query parameter "zoom" selects an OrthophotoZoom level, from 1 to 4.
 */
final class OrthophotoController extends AbstractController
{
    /**
     * The time, in seconds, that a client can keep an image: 7 days.
     */
    private const int MAX_AGE = 604800;

    #[Route(
        path: '/orthophoto/{x},{y}.jpg',
        name: 'app_orthophoto',
        requirements: ['x' => '\d{6}', 'y' => '\d{7}'],
        methods: [Request::METHOD_GET],
    )]
    public function image(
        OrthophotoClient $client,
        int $x,
        int $y,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        OrthophotoZoom $zoom = OrthophotoZoom::Default,
    ): Response {
        if (!OrthophotoClient::contains($x, $y)) {
            throw new NotFoundHttpException('The point is not in Denmark.');
        }

        try {
            $jpeg = $client->image($x, $y, $zoom);
        } catch (\RuntimeException $exception) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, $exception->getMessage(), $exception);
        }

        return new Response($jpeg, Response::HTTP_OK, ['Content-Type' => 'image/jpeg'])
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
