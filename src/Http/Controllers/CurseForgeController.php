<?php

declare(strict_types=1);

namespace ModpackSwitcher\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

class CurseForgeController
{
    public function versions(Request $request, Server $server, string $project, \ModpackSwitcher\Services\CurseForgeApi $api): JsonResponse
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,9}$/', $project), 422, 'Invalid CurseForge project ID.');
        $input = $request->validate(['page' => ['required', 'integer', 'min:0', 'max:199']]);
        $page = (int) $input['page'];
        $cacheKey = 'modpack-switcher:curseforge:versions:'.hash('sha256', $api->key().$project.':'.$page);
        $data = Cache::remember($cacheKey, 60, function () use ($api, $project, $page): array {
            $pack = $api->get('/v1/mods/'.$project)['data'];
            abort_unless(($pack['gameId'] ?? null) === 432 && ($pack['classId'] ?? null) === 4471, 422, 'This project is not a Minecraft modpack.');
            $body = $api->get('/v1/mods/'.$project.'/files', ['index' => $page * 50, 'pageSize' => 50]);
            abort_unless((int) ($body['pagination']['index'] ?? -1) === $page * 50, 502, 'CurseForge returned the wrong version page.');
            $versions = [];
            foreach ($body['data'] as $file) {
                if (($file['isServerPack'] ?? false) || ! ($file['isAvailable'] ?? false)) {
                    continue;
                }
                $tags = $file['gameVersions'] ?? [];
                $versions[] = [
                    'id' => (string) $file['id'], 'name' => $file['displayName'],
                    'version_number' => $file['displayName'],
                    'version_type' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$file['releaseType'] ?? 1] ?? 'release',
                    'game_versions' => array_values(array_filter($tags, fn ($tag) => preg_match('/^[0-9]/', $tag))),
                    'loaders' => array_values(array_filter($tags, fn ($tag) => in_array(strtolower($tag), ['forge', 'neoforge', 'fabric', 'quilt'], true))),
                    'date_published' => $file['fileDate'], 'downloads' => $file['downloadCount'] ?? 0,
                ];
            }
            usort($versions, fn ($a, $b) => strcmp($b['date_published'], $a['date_published']));
            return ['versions' => $versions, 'nextPage' => ($page + 1) * 50 < min(10000, (int) ($body['pagination']['totalCount'] ?? 0)) ? $page + 1 : null];
        });
        return new JsonResponse($data);
    }

    public function search(Request $request, Server $server, ExtensionSettingsRegistry $settings): JsonResponse
    {
        // The server route middleware checks access; browsing does not require install privileges.
        $input = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'sort' => ['required', Rule::in(['relevance', 'downloads', 'updated', 'newest'])],
            'page' => ['required', 'integer', 'min:0', 'max:833'],
            'minecraft' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'loader' => ['nullable', Rule::in(['forge', 'fabric', 'neoforge', 'quilt'])],
        ]);
        $key = (string) $settings->get('modpack-switcher')?->get('curseforge_api_key');
        abort_if(trim($key) === '', 422, 'Ask an administrator to configure the global CurseForge API key in Modpack Switcher settings.');
        $offset = (int) $input['page'] * 12;
        $params = [
            'gameId' => 432, 'classId' => 4471,
            'index' => $offset, 'pageSize' => min(12, 10000 - $offset),
        ];
        if (! empty($input['query'])) {
            $params['searchFilter'] = $input['query'];
        }
        $params['sortField'] = ['relevance' => 2, 'downloads' => 6, 'updated' => 3, 'newest' => 11][$input['sort']];
        $params['sortOrder'] = 'desc';
        $loaderTypes = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];
        $params['modLoaderTypes'] = json_encode(empty($input['loader']) ? [] : [$loaderTypes[$input['loader']]]);
        if (! empty($input['minecraft'])) {
            $params['gameVersion'] = $input['minecraft'];
        }
        // A key change creates a new cache namespace. Never cache credentials or return upstream errors.
        $cacheKey = 'modpack-switcher:curseforge:v4:'.hash('sha256', $key.json_encode($params));
        $result = Cache::remember($cacheKey, 60, function () use ($key, $params, $offset): array {
            try {
                $response = Http::baseUrl('https://api.curseforge.com')->acceptJson()
                    ->withUserAgent('ModpackSwitcher/1.0 (Pterodactyl extension)')
                    ->withHeaders(['x-api-key' => $key])->withoutRedirecting()->connectTimeout(5)->timeout(15)
                    ->get('/v1/mods/search', $params);
            } catch (ConnectionException) {
                abort(502, 'Could not reach CurseForge. Please try again.');
            }
            abort_if(in_array($response->status(), [401, 403], true), 502, 'CurseForge rejected the API key. Ask an administrator to check it.');
            abort_if($response->status() === 429, 429, 'CurseForge is receiving too many requests. Please wait and try again.');
            abort_unless($response->successful(), 502, 'Could not load CurseForge modpacks. Please try again.');
            $body = $response->json();
            abort_unless(is_array($body) && is_array($body['data'] ?? null) && is_array($body['pagination'] ?? null), 502, 'CurseForge returned an invalid response.');
            abort_unless((int) ($body['pagination']['index'] ?? -1) === $offset, 502, 'CurseForge returned a cached page that does not match this request. Please try again later.');
            $hits = array_map(fn (array $pack): array => [
                'project_id' => (string) $pack['id'],
                'slug' => $pack['slug'], 'title' => $pack['name'],
                'author' => implode(', ', array_column($pack['authors'] ?? [], 'name')) ?: 'Unknown author',
                'description' => $pack['summary'] ?? '',
                'icon_url' => $pack['logo']['thumbnailUrl'] ?? $pack['logo']['url'] ?? null,
                'downloads' => $pack['downloadCount'] ?? 0,
                'categories' => array_column($pack['categories'] ?? [], 'name'),
                'versions' => array_values(array_unique(array_column($pack['latestFilesIndexes'] ?? [], 'gameVersion'))),
            ], $body['data']);

            return ['hits' => $hits, 'total_hits' => min(10000, (int) ($body['pagination']['totalCount'] ?? 0)), 'offset' => $offset, 'limit' => 12];
        });

        return new JsonResponse($result);
    }
}
