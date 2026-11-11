<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Throwable;

class CurrentPack
{
    public function __construct(private ProviderCatalog $catalog, private ManagedInstallations $managed, private CurseForgeApi $curseforge) {}

    public function configuration(Server $server): ?array
    {
        $candidates = [];
        foreach (['modrinth', 'curseforge', 'ftb'] as $provider) {
            $egg = $this->catalog->egg($provider);
            if ($egg?->id === $server->egg_id) $candidates[$provider] = $egg;
        }
        $marked = $this->managed->provider($server);
        $provider = isset($candidates[$marked ?? '']) ? $marked : (count($candidates) === 1 ? array_key_first($candidates) : null);
        if ($provider === null) return null;
        $egg = $candidates[$provider];
        $projectKey = $provider === 'ftb' ? 'FTB_MODPACK_ID' : 'PROJECT_ID';
        $versionKey = $provider === 'ftb' ? 'FTB_MODPACK_VERSION_ID' : 'VERSION_ID';
        $values = $egg->variables->whereIn('env_variable', [$projectKey, $versionKey])->mapWithKeys(fn ($v) => [$v->env_variable => $v->default_value])->all();
        $stored = ServerVariable::query()->where('server_id', $server->id)
            ->whereHas('variable', fn ($q) => $q->where('egg_id', $server->egg_id)->whereIn('env_variable', [$projectKey, $versionKey]))
            ->with('variable:id,env_variable')->get();
        foreach ($stored as $value) $values[$value->variable->env_variable] = $value->variable_value;
        $project = (string) ($values[$projectKey] ?? '');
        if (! preg_match($provider === 'modrinth' ? '/^[a-zA-Z0-9]{8}$/' : '/^[1-9][0-9]{0,9}$/', $project)) return null;
        return [
            'provider' => $provider, 'project_id' => $project, 'version_id' => (string) (($values[$versionKey] ?? '') ?: 'latest'),
            'egg_id' => $egg->id, 'java_image' => $server->image,
            'java_label' => array_search($server->image, $egg->docker_images, true) ?: $server->image,
        ];
    }

    public function current(Server $server): ?array
    {
        $config = $this->configuration($server);
        if ($config === null) return null;
        $saved = $this->managed->installed($server);
        $matches = is_array($saved) && ($saved['provider'] ?? null) === $config['provider']
            && ($saved['project_id'] ?? null) === $config['project_id'] && ($saved['version_id'] ?? null) === $config['version_id'];
        $details = $matches ? $saved : $this->describe($config['provider'], $config['project_id'], $config['version_id']);
        return [...$details, ...$config, 'status' => $server->status, 'skip_scripts' => $server->skip_scripts];
    }

    private function publicGet(string $url): array
    {
        $response = Http::withUserAgent('ModpackSwitcher/1.0 (Pterodactyl extension; author davidhepp)')
            ->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)->get($url);
        abort_unless($response->successful() && is_array($response->json()), 502);
        return $response->json();
    }

    public function describe(string $provider, string $project, string $version): array
    {
        return Cache::remember('modpack-switcher:details:v1:'.hash('sha256', $provider.':'.$project.':'.$version), 300, function () use ($provider, $project, $version): array {
            $label = ['ftb' => 'FTB', 'modrinth' => 'Modrinth', 'curseforge' => 'CurseForge'][$provider];
            $pack = ['project_id' => $project, 'slug' => $project, 'title' => $label.' pack '.$project, 'author' => $label,
                'description' => '', 'icon_url' => null, 'downloads' => 0, 'categories' => [], 'versions' => []];
            $name = $version === 'latest' ? 'Latest (configured)' : $version;
            $minecraft = $loaders = [];
            try {
                if ($provider === 'modrinth') {
                    $p = $this->publicGet('https://api.modrinth.com/v2/project/'.rawurlencode($project));
                    $v = $this->publicGet('https://api.modrinth.com/v2/version/'.rawurlencode($version));
                    $pack = [...$pack, 'title' => $p['title'], 'slug' => $p['slug'], 'description' => $p['description'], 'icon_url' => $p['icon_url'] ?? null];
                    $name = $v['version_number'] ?? $v['name'] ?? $name;
                    $minecraft = $v['game_versions'] ?? []; $loaders = $v['loaders'] ?? [];
                } elseif ($provider === 'curseforge') {
                    $p = $this->curseforge->get('/v1/mods/'.$project)['data'];
                    $v = $this->curseforge->get('/v1/mods/'.$project.'/files/'.$version)['data'];
                    $pack = [...$pack, 'title' => $p['name'], 'slug' => $p['slug'], 'description' => $p['summary'] ?? '', 'icon_url' => $p['logo']['thumbnailUrl'] ?? null];
                    $name = $v['displayName'] ?? $name;
                    $minecraft = array_values(array_filter($v['gameVersions'] ?? [], fn ($tag) => preg_match('/^[0-9]/', $tag)));
                    $loaders = array_values(array_filter($v['gameVersions'] ?? [], fn ($tag) => in_array(strtolower($tag), ['forge', 'fabric', 'quilt', 'neoforge'], true)));
                } else {
                    $p = $this->publicGet('https://api.feed-the-beast.com/v1/modpacks/public/modpack/'.$project);
                    $v = collect($p['versions'] ?? [])->first(fn ($v) => (string) $v['id'] === $version) ?? [];
                    $pack = [...$pack, 'title' => $p['name'], 'slug' => $p['slug'], 'description' => $p['synopsis'] ?? '', 'icon_url' => collect($p['art'] ?? [])->firstWhere('type', 'square')['url'] ?? null];
                    $name = $v['name'] ?? $name;
                    foreach ($v['targets'] ?? [] as $target) {
                        if ($target['name'] === 'minecraft') $minecraft[] = $target['version'];
                        if ($target['type'] === 'modloader') $loaders[] = $target['name'];
                    }
                }
            } catch (Throwable) {
                // Configuration remains useful when a provider is unavailable.
            }
            $pack['versions'] = $minecraft;
            return ['provider' => $provider, 'project_id' => $project, 'version_id' => $version,
                'pack' => $pack, 'version_name' => $name, 'minecraft' => $minecraft, 'loaders' => $loaders];
        });
    }
}
