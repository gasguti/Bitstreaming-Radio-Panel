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
        path: '/admin/station/{id}/suspend',
        operationId: 'postAdminStationSuspend',
        summary: 'Suspend a station (disable broadcasting, stop backend and frontend). Idempotent.',
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
final class SuspendAction extends StationsController implements SingleActionInterface
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

        // Idempotente: si ya está deshabilitada, éxito sin hacer nada.
        if (!$record->is_enabled) {
            return $response->withJson(
                new Status(true, __('Station is already disabled.'))
            );
        }

        // BRP-FORK: suspensión real para WHMCS. Deshabilitar equivale a la
        // opción "Disable" del panel (Station.is_enabled=false): mata backend
        // (Liquidsoap) + frontend (Icecast) vía removeLocalServices y bloquea
        // el re-encendido para no-admins (hasCommand retorna false).
        // No eliminar en merge upstream.
        $record->is_enabled = false;
        $this->em->persist($record);
        $this->em->flush();

        try {
            $this->configuration->writeConfiguration(
                station: $record,
                forceRestart: true
            );
        } catch (Throwable $e) {
            // Camino normal: writeConfiguration llama a removeLocalServices()
            // (detiene y borra el grupo supervisor) y luego lanza
            // RuntimeException('Station is disabled.'). Cualquier otro error
            // también se tolera porque el flag ya quedó persistido.
            $this->logger->info(
                'Station suspended via API.',
                [
                    'station_id' => $record->id,
                    'station_name' => $record->name,
                    'exception' => $e->getMessage(),
                ]
            );
        }

        return $response->withJson(
            new Status(true, __('Station disabled.'))
        );
    }
}
