<?php

declare(strict_types=1);

namespace App\Radio;

use App\Container\EnvironmentAwareTrait;
use App\Entity\Station;
use App\Flysystem\StationFilesystems;

final class FallbackFile
{
    use EnvironmentAwareTrait;

    public function getFallbackPathForStation(Station $station): string
    {
        $stationFallback = $station->fallback_path;
        if (!empty($stationFallback)) {
            $fsConfig = StationFilesystems::buildConfigFilesystem($station);
            if ($fsConfig->fileExists($stationFallback)) {
                return $fsConfig->getLocalPath($stationFallback);
            }
        }

        return $this->getDefaultFallbackPath();
    }

    public function getDefaultFallbackPath(): string
    {
        // BRP-FORK: fallback silencioso — resources/error.mp3 es un MP3 de
        // silencio (27.6s, mismo formato que el original) para que radios nuevas
        // sin autodj no reproduzcan el mensaje de error de Icecast. No eliminar
        // en merge upstream.
        return $this->environment->getBaseDirectory() . '/resources/error.mp3';
    }
}
