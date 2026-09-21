<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin\Stations;

use App\Controller\Api\Admin\StationsController;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Api\Status;
use App\Entity\Station;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Throwable;

#[
    OA\Post(
        path: '/admin/station/{id}/unsuspend',
        operationId: 'postAdminStationUnsuspend',
        summary: 'Unsuspend a station (enable broadcasting, restart backend and frontend). Idempotent.',
        tags: [OpenApi::TAG_ADMIN_STATIONS],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Station ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class UnsuspendAction extends StationsController implements SingleActionInterface
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $record = $this->getRecord($request, $params);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(Error::notFound());
        }

        assert($record instanceof Station);

        // Idempotente: si ya está habilitada, éxito sin hacer nada.
        if ($record->is_enabled) {
            return $response->withJson(
                new Status(true, __('Station is already enabled.'))
            );
        }

        // BRP-FORK: reactivación para WHMCS. Habilitar equivale a la opción
        // "Enable" del panel (Station.is_enabled=true) + auto-arranque
        // (has_started=true) para que la radio vuelva sola tras el pago,
        // sin que el dueño tenga que pulsar Play. No eliminar en merge upstream.
        $record->is_enabled = true;
        $record->has_started = true;
        $this->em->persist($record);
        $this->em->flush();

        try {
            $this->configuration->writeConfiguration(
                station: $record,
                forceRestart: true
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Station unsuspend failed during configuration write.',
                [
                    'station_id' => $record->id,
                    'station_name' => $record->name,
                    'exception' => $e->getMessage(),
                ]
            );

            return $response->withStatus(500)
                ->withJson(
                    new Error(
                        code: 500,
                        message: __('Station enabled but services could not be restarted. Check the logs.')
                    )
                );
        }

        // Reabrir los proxy de escucha (/radio/{puerto}, /hls) en nginx.
        $this->nginx->writeConfiguration($record);

        return $response->withJson(
            new Status(true, __('Station enabled.'))
        );
    }
}
