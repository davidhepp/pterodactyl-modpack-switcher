<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;

class ManagedInstallations
{
    public function provider(Server $server): ?string
    {
        return (new ExtensionSettings('modpack-switcher'))->forServer($server)->get('managed_provider');
    }

    public function mark(Server $server, string $provider): void
    {
        (new ExtensionSettings('modpack-switcher'))->forServer($server)->set('managed_provider', $provider);
    }
}
