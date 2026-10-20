<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin\Stations;

use App\Controller\Api\Admin\StationsController;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Error;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\Station;
use App\Entity\StationStreamer;
use App\Entity\User;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\Enums\BackendAdapters;
use App\Radio\Enums\CrossfadeModes;
use App\Radio\Enums\FrontendAdapters;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Throwable;

#[
    OA\Post(
        path: '/admin/whmcs/provision',
        operationId: 'postAdminWhmcsProvision',
        summary: 'Provision a station + role + user in one call (WHMCS CreateAccount).',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'station_name',
                        description: 'Display name of the station (also used for role name, display name and live broadcast text).',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'email',
                        description: 'E-mail of the station owner (WHMCS client). If it already owns a panel user, the new station is added to the existing user and role.',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'password',
                        description: 'Password for the Icecast source (per-station, stored per WHMCS service). For a new e-mail it also becomes the panel login password; for an existing e-mail the panel login keeps the first radio password.',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'enable_hls',
                        description: 'Enable HLS (depends on the WHMCS plan).',
                        type: 'boolean'
                    ),
                    new OA\Property(
                        property: 'crossfade_type',
                        description: 'Crossfade mode: smart, normal or none. Defaults to normal.',
                        type: 'string'
                    ),
                ]
            )
        ),
        tags: [OpenApi::TAG_ADMIN_STATIONS],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class ProvisionAction extends StationsController implements SingleActionInterface
{
    /**
     * Station permissions assigned to the owner role.
     * Mirrors the manual checkbox selection (all except Profile,
     * MountPoints, WebHooks and Podcasts; no "All" wildcard).
     */
    public const array OWNER_PERMISSIONS = [
        'view station management',
        'view station reports',
        'view station logs',
        'manage station broadcasting',
        'manage station streamers',
        'manage station remotes',
        'manage station media',
        'delete station media',
        'manage station automation',
    ];

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $data = (array)$request->getParsedBody();

        $stationName = trim((string)($data['station_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ('' === $stationName || '' === $email || '' === $password) {
            return $response->withStatus(400)
                ->withJson(new Error(400, __('station_name, email and password are required.')));
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $response->withStatus(400)
                ->withJson(new Error(400, __('Invalid e-mail address.')));
        }

        // 1. Station -------------------------------------------------------
        // NOTE: no try/catch with partial rollback here on purpose (option B):
        // if the user step fails below, station+role are removed so a WHMCS
        // retry starts clean instead of hitting "short_name already exists".
        $station = new Station();
        $station->name = $stationName;
        $station->frontend_type = FrontendAdapters::Icecast;
        $station->backend_type = BackendAdapters::Liquidsoap;
        $station->enable_streamers = true;
        $station->enable_hls = (bool)($data['enable_hls'] ?? false);

        $crossfade = strtolower(trim((string)($data['crossfade_type'] ?? '')));
        $backendConfig = $station->backend_config;
        if ('' !== $crossfade) {
            $mode = CrossfadeModes::tryFrom($crossfade) ?? CrossfadeModes::default();
            $backendConfig->crossfade_type = $mode->value;
        }
        $backendConfig->live_broadcast_text = $stationName;
        $station->backend_config = $backendConfig;

        // Same password for the user and the Icecast source (encoder/DJ).
        $frontendConfig = $station->frontend_config;
        $frontendConfig->source_pw = $password;
        $station->frontend_config = $frontendConfig;

        try {
            $errors = $this->validator->validate($station);
            if (count($errors) > 0) {
                return $response->withStatus(400)
                    ->withJson(
                        new Error(
                            400,
                            __('Station validation failed: ') . ((string)$errors)
                        )
                    );
            }

            $station = $this->handleCreate($station);
            $this->em->flush();

            // BRP-FORK: arrancar la estación al crearla (equivale al botón
            // "Iniciar Estación" / restart). Sin has_started=true,
            // writeConfiguration aborta con "Station has not started yet"
            // y la radio queda detenida. No eliminar en merge upstream.
            $station->has_started = true;
            $this->em->persist($station);
            $this->em->flush();

            // BRP-FORK: DJ por defecto (usuario "source", clave = source_pw,
            // texto "En Vivo"). No interfiere con el resto del provision:
            // si ya existe (reintento WHMCS), se omite. No eliminar en merge
            // upstream.
            $existingStreamer = $this->em->getRepository(StationStreamer::class)
                ->findOneBy(['station' => $station, 'streamer_username' => 'source']);
            if (null === $existingStreamer) {
                $streamer = new StationStreamer($station);
                $streamer->streamer_username = 'source';
                $streamer->streamer_password = $password;
                $streamer->display_name = 'En Vivo';
                $streamer->is_active = true;

                $errors = $this->validator->validate($streamer);
                if (count($errors) > 0) {
                    throw new \RuntimeException((string)$errors);
                }

                $this->em->persist($streamer);
                $this->em->flush();
            }
        } catch (Throwable $e) {
            return $response->withStatus(400)
                ->withJson(new Error(400, $e->getMessage()));
        }

        // 2+3. Role + User -------------------------------------------------
        // Multi-radio: one panel user per e-mail can own several stations.
        // If the e-mail already exists, reuse that user + its owner role and
        // only add the new station's permissions to the existing role.
        // NOTE: the panel login password stays as the first radio's password;
        // each station's encoder/source password (source_pw) is per-service
        // and stored in that WHMCS service's password field.
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $existing) {
            $user = $existing;
            $existingRole = $user->roles->first();
            if (false === $existingRole || null === $existingRole) {
                $role = new Role();
                $role->name = $stationName;
                $this->em->persist($role);
                $user->roles->add($role);
            } else {
                $role = $existingRole;
            }

            try {
                foreach (self::OWNER_PERMISSIONS as $permission) {
                    // Validate against the ACL so renamed/removed permissions fail loudly.
                    if (!$request->getAcl()->isValidPermission($permission, false)) {
                        continue;
                    }
                    $this->em->persist(new RolePermission($role, $station, $permission));
                }
                $this->em->persist($user);
                $this->em->flush();
            } catch (Throwable $e) {
                // Rollback only the station (user + role are pre-existing).
                try {
                    $this->handleDelete($station);
                    $this->em->flush();
                } catch (Throwable) {
                }

                return $response->withStatus(400)
                    ->withJson(new Error(400, $e->getMessage()));
            }

            $isNewUser = false;
        } else {
            $role = new Role();
            $role->name = $stationName;
            $this->em->persist($role);

            foreach (self::OWNER_PERMISSIONS as $permission) {
                // Validate against the ACL so renamed/removed permissions fail loudly.
                if (!$request->getAcl()->isValidPermission($permission, false)) {
                    continue;
                }
                $this->em->persist(new RolePermission($role, $station, $permission));
            }
            $this->em->flush();

            $user = new User();
            $user->email = $email;
            $user->name = $stationName;
            $user->setNewPassword($password);
            $user->roles->add($role);

            try {
                $errors = $this->validator->validate($user);
                if (count($errors) > 0) {
                    throw new \RuntimeException((string)$errors);
                }

                $this->em->persist($user);
                $this->em->flush();
            } catch (Throwable $e) {
                // Rollback: remove the role + station created above so WHMCS
                // can retry cleanly. The station removal also clears its
                // supervisor config via handleDelete.
                try {
                    $this->handleDelete($station);
                    $this->em->remove($role);
                    $this->em->flush();
                } catch (Throwable) {
                }

                return $response->withStatus(400)
                    ->withJson(new Error(400, $e->getMessage()));
            }

            $isNewUser = true;
        }

        // Reload ACL cache so the new role takes effect immediately.
        $request->getAcl()->reload();

        // Write radio configuration now that everything exists.
        try {
            $this->configuration->writeConfiguration(
                station: $station,
                forceRestart: true
            );
        } catch (Throwable) {
        }
        $this->nginx->writeConfiguration($station);

        $this->logger->info(
            'Station provisioned via WHMCS API.',
            [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_new_user' => $isNewUser,
            ]
        );

        return $response->withJson([
            'success' => true,
            'station_id' => $station->id,
            'short_name' => $station->short_name,
            'user_id' => $user->id,
            'role_id' => $role->id,
            // BRP-FORK: false cuando el e-mail ya tenía usuario/rol (multiradio):
            // la estación nueva se agregó a ese rol existente. El login del
            // panel sigue con la clave de la primera radio; la clave de esta
            // radio aplica solo a su encoder/source (source_pw por servicio).
            'is_new_user' => $isNewUser,
            // BRP-FORK: puerto DJ/Streamer ("Personalizar el Puerto para el
            // DJ/Streamer" = backend_config.dj_port = base+5). WHMCS lo guarda
            // en el custom field "Puerto Icecast" ($service_custom_field_puertoicecast).
            // Se incluye también frontend_port (Icecast, base) por referencia.
            'dj_port' => $station->backend_config->dj_port,
            'frontend_port' => $station->frontend_config->port,
        ]);
    }
}
