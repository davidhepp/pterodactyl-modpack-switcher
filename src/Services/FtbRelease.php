<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FtbRelease
{
    private function get(string $path): array
    {
        try {
            $response = Http::withUserAgent('ModpackSwitcher/1.0 (Pterodactyl extension; author davidhepp)')
                ->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->get('https://api.feed-the-beast.com/v1/modpacks/public/modpack/'.$path);
        } catch (ConnectionException) {
            abort(502, 'Could not reach FTB. Please try again.');
        }
        abort_unless($response->successful(), 502, 'Could not load FTB version information. Please try again.');
        $data = $response->json();
        abort_unless(is_array($data) && ($data['status'] ?? null) === 'success', 422, 'This FTB pack or version is no longer available.');
        return $data;
    }

    private function pack(string $id): array
    {
        $pack = $this->get($id);
        abort_unless((string) ($pack['id'] ?? '') === $id && ! ($pack['private'] ?? false) && ($pack['type'] ?? '') !== 'archived', 422, 'This FTB pack is not publicly available.');
        return $pack;
    }

    public function versions(string $project): array
    {
        return Cache::remember('modpack-switcher:ftb:versions:'.$project, 60, function () use ($project): array {
            $pack = $this->pack($project);
            $versions = [];
            foreach ($pack['versions'] ?? [] as $version) {
                if (($version['private'] ?? false) || ($version['type'] ?? '') === 'archived') continue;
                $targets = $version['targets'] ?? [];
                $minecraft = array_values(array_map(fn ($t) => $t['version'], array_filter($targets, fn ($t) => ($t['name'] ?? '') === 'minecraft')));
                if ($minecraft === []) continue;
                $versions[] = [
                    'id' => (string) $version['id'], 'name' => $version['name'], 'version_number' => $version['name'],
                    'version_type' => in_array($version['type'] ?? '', ['alpha', 'beta'], true) ? $version['type'] : 'release',
                    'game_versions' => $minecraft,
                    'loaders' => array_values(array_map(fn ($t) => $t['name'], array_filter($targets, fn ($t) => ($t['type'] ?? '') === 'modloader'))),
                    'date_published' => gmdate('c', (int) ($version['released'] ?? $version['updated'] ?? 0)),
                    'downloads' => null,
                ];
            }
            usort($versions, fn ($a, $b) => strcmp($b['date_published'], $a['date_published']));
            return ['versions' => $versions, 'nextPage' => null];
        });
    }

    public function validate(string $project, string $version): array
    {
        // Fetch fresh metadata for installation; browsing caches are not authoritative.
        $pack = $this->pack($project);
        $selected = collect($pack['versions'] ?? [])->first(fn ($item) => (string) ($item['id'] ?? '') === $version);
        abort_unless(is_array($selected) && ! ($selected['private'] ?? false) && ($selected['type'] ?? '') !== 'archived', 422, 'The selected FTB version is unavailable or does not belong to this pack.');
        $manifest = $this->get($project.'/'.$version);
        abort_unless((string) ($manifest['id'] ?? '') === $version && (string) ($manifest['parent'] ?? '') === $project
            && ! ($manifest['private'] ?? false) && ($manifest['type'] ?? '') !== 'archived'
            && ! empty($manifest['files']), 422, 'FTB returned an unavailable or invalid version manifest.');
        return $manifest;
    }
}
