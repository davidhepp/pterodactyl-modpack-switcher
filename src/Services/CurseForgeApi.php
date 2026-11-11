<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

class CurseForgeApi
{
    public function __construct(private ExtensionSettingsRegistry $settings) {}

    public function key(): string
    {
        $key = (string) $this->settings->get('modpack-switcher')?->get('curseforge_api_key');
        abort_if(trim($key) === '', 422, 'Ask an administrator to configure the global CurseForge API key in Modpack Switcher settings.');
        return $key;
    }

    public function get(string $path, array $query = []): array
    {
        try {
            $response = Http::baseUrl('https://api.curseforge.com')->acceptJson()
                ->withUserAgent('ModpackSwitcher/1.0 (Pterodactyl extension)')
                ->withHeaders(['x-api-key' => $this->key()])->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->get($path, $query);
        } catch (ConnectionException) {
            abort(502, 'Could not reach CurseForge. Please try again.');
        }
        abort_if(in_array($response->status(), [401, 403], true), 502, 'CurseForge rejected the API key. Ask an administrator to check it.');
        abort_if($response->status() === 429, 429, 'CurseForge is receiving too many requests. Please wait and try again.');
        abort_if($response->status() === 404, 422, 'This CurseForge project or file is no longer available.');
        abort_unless($response->successful(), 502, 'Could not load CurseForge data. Please try again.');
        $body = $response->json();
        abort_unless(is_array($body) && array_key_exists('data', $body), 502, 'CurseForge returned an invalid response.');
        return $body;
    }
}
