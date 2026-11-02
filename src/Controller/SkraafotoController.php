<?php

declare(strict_types=1);

namespace App\Controller;

use App\Skraafoto\SkraafotoClient;
use App\Skraafoto\SkraafotoNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sends the thumbnail of an oblique photo, with the token kept on the server.
 */
final class SkraafotoController extends AbstractController
{
    /**
     * The time, in seconds, that a client can keep a thumbnail.
     */
    private const int MAX_AGE = 86400;

    #[Route(
        path: '/skraafoto/{collection}/{photo}.jpg',
        name: 'app_skraafoto_thumbnail',
        requirements: ['collection' => 'skraafotos\d{4}', 'photo' => '[A-Za-z0-9_-]+'],
        methods: [Request::METHOD_GET],
    )]
    public function thumbnail(SkraafotoClient $client, string $collection, string $photo): Response
    {
        try {
            $jpeg = $client->thumbnail($collection, $photo);
        } catch (SkraafotoNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        return new Response($jpeg, Response::HTTP_OK, ['Content-Type' => 'image/jpeg'])
            ->setPublic()
            ->setMaxAge(self::MAX_AGE);
    }
}
