<?php

declare(strict_types=1);

namespace App\Controller\Frontend;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Station;
use App\Enums\StationPermissions;
use App\Exception\Http\InvalidRequestAttribute;
use App\Http\Response;
use App\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;

final class IndexAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        // Redirect to complete setup, if it hasn't been completed yet.
        $settings = $request->getSettings();

        if (!$settings->isSetupComplete()) {
            return $response->withRedirect($request->getRouter()->named('setup:index'));
        }

        // Redirect to login screen if the user isn't logged in.
        try {
            $request->getUser();

            // BRP-FORK: si el usuario tiene acceso a exactamente 1 estación,
            // redirigir directo a su panel (/station/{id}) en vez del dashboard.
            // No eliminar en merge upstream.
            $acl = $request->getAcl();
            $userStations = array_values(
                array_filter(
                    $this->em->getRepository(Station::class)->findBy([
                        'is_enabled' => 1,
                    ]),
                    static fn(Station $station) => $acl->isAllowed(
                        StationPermissions::View,
                        $station->id
                    )
                )
            );

            if (1 === count($userStations)) {
                return $response->withRedirect(
                    $request->getRouter()->named(
                        'stations:index:index',
                        ['station_id' => $userStations[0]->id]
                    )
                );
            }

            // Redirect to dashboard if no other custom redirection rules exist.
            return $response->withRedirect($request->getRouter()->named('dashboard'));
        } catch (InvalidRequestAttribute) {
            // Redirect to a custom homepage URL if specified in settings.
            $homepageRedirect = $settings->homepage_redirect_url;
            if (null !== $homepageRedirect) {
                return $response->withRedirect($homepageRedirect, 302);
            }

            return $response->withRedirect($request->getRouter()->named('account:login'));
        }
    }
}
