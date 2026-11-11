<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Request;
use Pterodactyl\Services\Servers\ServerInstallService;

class PreservingInstallService extends ServerInstallService
{
    public function __construct(private ServerInstallService $inner, private ManagedInstallations $managed, private FilePolicy $files) {}

    public function info(Request $request, string $uuid): array
    {
        $info = $this->inner->info($request, $uuid);
        $server = $this->inner->resolveForNode($request, $uuid);
        $pending = $this->managed->pending($server);
        if (($pending['file_policy'] ?? null) === 'keep_world' && ($pending['egg_id'] ?? null) === $server->egg_id) {
            $info['script'] = $this->files->script($info['script'] ?? '', $pending['files']);
        }
        return $info;
    }
}
