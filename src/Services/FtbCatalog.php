<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FtbCatalog
{
    private const BASE = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack';
    private const AGENT = 'ModpackSwitcher/1.0 (Pterodactyl extension; author Dasky)';
    private const CACHE_KEY = 'modpack-switcher:ftb:catalog:v1';

    public function packs(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $lock = Cache::lock(self::CACHE_KEY.':refresh', 120);
        abort_unless($lock->get(), 503, 'The FTB catalog is loading. Please try again in a moment.');
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) return $cached;
            try {
                $response = Http::withUserAgent(self::AGENT)->acceptJson()->withoutRedirecting()
                    ->connectTimeout(3)->timeout(10)->get(self::BASE.'/all');
                $index = $response->json();
                abort_unless($response->successful() && is_array($index) && ($index['status'] ?? null) === 'success' && is_array($index['packs'] ?? null), 502, 'Could not load the FTB catalog. Please try again.');
                $ids = array_values(array_unique($index['packs']));
                abort_unless(count($ids) <= 1000 && array_all($ids, fn ($id) => is_int($id) && $id > 0), 502, 'FTB returned an invalid catalog.');
                $packs = [];
                // Limit concurrent requests and cache the compact normalized catalog,
                // not the large manifests, for all users of this Panel.
                foreach (array_chunk($ids, 12) as $batch) {
                    $responses = Http::pool(fn (Pool $pool) => array_map(fn ($id) => $pool->as((string) $id)
                        ->withUserAgent(self::AGENT)->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)
                        ->get(self::BASE.'/'.$id), $batch));
                    foreach ($batch as $id) {
                        $result = $responses[(string) $id] ?? null;
                        abort_unless($result instanceof Response && $result->successful(), 502, 'Could not load all FTB packs. Please try again.');
                        $body = $result->json();
                        abort_unless(is_array($body) && ($body['status'] ?? null) === 'success' && ($body['id'] ?? null) === $id, 502, 'FTB returned invalid pack metadata.');
                        $pack = $this->normalize($body);
                        if ($pack !== null) $packs[] = $pack;
                    }
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                throw $exception;
            } catch (Throwable) {
                abort(502, 'Could not reach FTB. Please try again.');
            }
            Cache::put(self::CACHE_KEY, $packs, 900);
            return $packs;
        } finally {
            $lock->release();
        }
    }

    private function normalize(array $pack): ?array
    {
        if (($pack['private'] ?? false) || ($pack['type'] ?? '') === 'archived') return null;
        $targets = [];
        foreach ($pack['versions'] ?? [] as $version) {
            if (($version['private'] ?? false) || ($version['type'] ?? '') === 'archived') continue;
            $minecraft = null;
            $loader = null;
            foreach ($version['targets'] ?? [] as $target) {
                if (($target['name'] ?? '') === 'minecraft') $minecraft = $target['version'] ?? null;
                if (($target['type'] ?? '') === 'modloader') $loader = strtolower($target['name'] ?? '');
            }
            if (is_string($minecraft)) $targets[$minecraft.':'.$loader] = ['minecraft' => $minecraft, 'loader' => $loader];
        }
        if ($targets === []) return null;
        $icon = collect($pack['art'] ?? [])->firstWhere('type', 'square')['url'] ?? null;
        return [
            'project_id' => (string) $pack['id'], 'slug' => $pack['slug'] ?? (string) $pack['id'],
            'title' => $pack['name'], 'description' => strip_tags($pack['synopsis'] ?? ''),
            'author' => implode(', ', array_column($pack['authors'] ?? [], 'name')) ?: 'FTB Team',
            'icon_url' => is_string($icon) && str_starts_with($icon, 'https://') ? $icon : null,
            'downloads' => (int) ($pack['installs'] ?? 0),
            'categories' => array_values(array_unique(array_column($pack['tags'] ?? [], 'name'))),
            'versions' => array_values(array_unique(array_column($targets, 'minecraft'))),
            '_targets' => array_values($targets), '_updated' => $pack['updated'] ?? 0,
            '_released' => $pack['released'] ?? 0, '_plays' => $pack['plays'] ?? 0,
        ];
    }

    public function search(string $query, string $minecraft, string $loader, string $sort, int $page): array
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        $matches = array_values(array_filter($this->packs(), function (array $pack) use ($terms, $minecraft, $loader): bool {
            $text = mb_strtolower($pack['title'].' '.$pack['author'].' '.$pack['description'].' '.implode(' ', $pack['categories']));
            foreach ($terms as $term) if (! str_contains($text, $term)) return false;
            if ($minecraft === '' && $loader === '') return true;
            foreach ($pack['_targets'] as $target) {
                if (($minecraft === '' || $target['minecraft'] === $minecraft) && ($loader === '' || $target['loader'] === $loader)) return true;
            }
            return false;
        }));
        $score = fn ($pack) => ($query !== '' && str_contains(mb_strtolower($pack['title']), mb_strtolower($query))) ? 1 : 0;
        usort($matches, fn ($a, $b) => match ($sort) {
            'updated' => $b['_updated'] <=> $a['_updated'],
            'newest' => $b['_released'] <=> $a['_released'],
            'relevance' => ($score($b) <=> $score($a)) ?: ($b['_plays'] <=> $a['_plays']),
            default => $b['downloads'] <=> $a['downloads'],
        } ?: strnatcasecmp($a['title'], $b['title']));
        return [
            'hits' => array_map(fn ($pack) => array_diff_key($pack, array_flip(['_targets', '_updated', '_released', '_plays'])), array_slice($matches, $page * 12, 12)),
            'total_hits' => count($matches), 'offset' => $page * 12, 'limit' => 12,
        ];
    }
}
