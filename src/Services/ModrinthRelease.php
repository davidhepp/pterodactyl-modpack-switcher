<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Support\Facades\Http;

class ModrinthRelease
{
    public function validate(string $projectId, string $versionId): array
    {
        $client = Http::baseUrl('https://api.modrinth.com/v2')->acceptJson()
            ->withUserAgent('ModpackSwitcher/1.0 (Pterodactyl extension)')->connectTimeout(5)->timeout(20);
        $project = $client->get('/project/'.rawurlencode($projectId))->throw()->json();
        $version = $client->get('/version/'.rawurlencode($versionId))->throw()->json();
        abort_unless(($project['project_type'] ?? null) === 'modpack' && ($project['server_side'] ?? null) !== 'unsupported', 422, 'This project is not a server-compatible modpack.');
        abort_unless(($version['project_id'] ?? null) === $projectId, 422, 'The selected version does not belong to this modpack.');
        abort_if(in_array($version['environment'] ?? '', ['client_only', 'singleplayer_only'], true), 422, 'This version does not support servers.');
        abort_unless(count(array_intersect($version['loaders'] ?? [], ['forge', 'fabric', 'quilt'])) > 0, 422, 'This Modrinth egg supports Forge, Fabric, and Quilt only. NeoForge is not supported by its install script.');
        abort_unless(collect($version['files'] ?? [])->contains(fn (array $file) => ($file['primary'] ?? false) && str_ends_with(parse_url($file['url'] ?? '', PHP_URL_PATH) ?? '', '.mrpack')), 422, 'The selected version has no primary Modrinth pack archive.');

        return $version;
    }
}
