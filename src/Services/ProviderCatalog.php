<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

class ProviderCatalog
{
    public function __construct(private ExtensionSettingsRegistry $settings) {}

    public function egg(string $provider): ?Egg
    {
        $id = (int) $this->settings->get('modpack-switcher')?->get($provider.'_egg_id');
        return $id > 0 ? Egg::query()->with('variables')->find($id) : null;
    }

    public function modrinthEgg(): ?Egg
    {
        $egg = $this->egg('modrinth');
        if ($egg === null || ! $egg->variables->pluck('env_variable')->contains('PROJECT_ID')
            || ! $egg->variables->pluck('env_variable')->contains('VERSION_ID')
            || empty($egg->docker_images) || empty($egg->copy_script_install)) {
            return null;
        }

        return $egg;
    }

    public function curseforgeEgg(): ?Egg
    {
        $egg = $this->egg('curseforge');
        if ($egg === null || count(array_diff(['PROJECT_ID', 'VERSION_ID', 'API_KEY'], $egg->variables->pluck('env_variable')->all())) > 0
            || empty($egg->docker_images) || empty($egg->copy_script_install)) {
            return null;
        }
        return $egg;
    }

    public function ftbEgg(): ?Egg
    {
        $egg = $this->egg('ftb');
        if ($egg === null || count(array_diff(['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID'], $egg->variables->pluck('env_variable')->all())) > 0
            || empty($egg->docker_images) || empty($egg->copy_script_install)) {
            return null;
        }
        return $egg;
    }

    public function providers(): array
    {
        return array_map(function (string $id): array {
            $egg = match ($id) { 'modrinth' => $this->modrinthEgg(), 'curseforge' => $this->curseforgeEgg(), 'ftb' => $this->ftbEgg(), default => null };

            return [
                'id' => $id,
                'name' => ['modrinth' => 'Modrinth', 'ftb' => 'FTB', 'curseforge' => 'CurseForge'][$id],
                'egg_available' => $egg !== null,
                'message' => $egg === null
                    ? 'Ask an administrator to configure this provider’s egg ID in Modpack Switcher settings.'
                    : 'Ready',
                'images' => $egg !== null
                    ? array_map(fn ($label, $value) => ['label' => (string) $label, 'value' => $value], array_keys($egg->docker_images), array_values($egg->docker_images)) : [],
                'installer_image' => $egg?->copy_script_container,
            ];
        }, ['modrinth', 'ftb', 'curseforge']);
    }
}
