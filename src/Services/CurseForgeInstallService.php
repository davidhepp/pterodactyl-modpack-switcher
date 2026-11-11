<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Request;
use Pterodactyl\Services\Servers\ServerInstallService;

// Decorates the Panel's installation response without editing a shared egg. The
// global credential goes only to authenticated Wings, never to startup variables.
class CurseForgeInstallService extends ServerInstallService
{
    public const KEY_PLACEHOLDER = 'managed-by-modpack-switcher';

    public function __construct(
        private ServerInstallService $inner,
        private ProviderCatalog $catalog,
        private ManagedInstallations $managed,
        private CurseForgeApi $api,
    ) {}

    public function info(Request $request, string $uuid): array
    {
        $info = $this->inner->info($request, $uuid);
        $server = $this->inner->resolveForNode($request, $uuid);
        if ($this->managed->provider($server) !== 'curseforge' || $this->catalog->curseforgeEgg()?->id !== $server->egg_id) {
            return $info;
        }
        $script = $info['script'] ?? '';
        abort_unless(str_starts_with($script, '#!') && str_contains($script, "\n"), 422, 'The CurseForge egg has an invalid install script.');
        [$first, $rest] = explode("\n", $script, 2);
        $info['script'] = $first."\nset +x\nAPI_KEY=".escapeshellarg($this->api->key())."\n".$rest;
        return $info;
    }
}
