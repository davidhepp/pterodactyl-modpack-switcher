<?php

declare(strict_types=1);

namespace ModpackSwitcher;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class ModpackSwitcherProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->extend(\Pterodactyl\Services\Servers\ServerInstallService::class, fn ($inner, $app) =>
            new \ModpackSwitcher\Services\CurseForgeInstallService(
                $inner,
                $app->make(\ModpackSwitcher\Services\ProviderCatalog::class),
                $app->make(\ModpackSwitcher\Services\ManagedInstallations::class),
                $app->make(\ModpackSwitcher\Services\CurseForgeApi::class),
            )
        );
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
        $providers = ['modrinth' => 'Modrinth', 'ftb' => 'FTB', 'curseforge' => 'CurseForge'];
        $settings = [];
        foreach ($providers as $id => $name) {
            $settings[] = ExtensionSettingDefinition::make($id.'_egg_id', $id.'_egg_id', 0, ['required', 'integer', 'min:0'])
                ->label($name.' egg ID')
                ->help('Enter the installed egg ID for this provider. 0 disables the provider.')
                ->tab('Eggs and providers')
                ->field('number');
        }
        $settings[] = ExtensionSettingDefinition::make('curseforge_api_key', 'curseforge_api_key', '', ['nullable', 'string', 'max:2000'])
            ->label('CurseForge global API key')
            ->help('Used for CurseForge browsing and installation. Encrypted and backend-only; leave blank to keep the saved key.')
            ->tab('Eggs and providers')
            ->secret();
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), $settings));
    }
}
