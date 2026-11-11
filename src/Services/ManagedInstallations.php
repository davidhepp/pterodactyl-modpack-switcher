<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;

class ManagedInstallations
{
    public function pending(Server $server): ?array
    {
        return (new ExtensionSettings('modpack-switcher'))->forServer($server)->get('pending_install');
    }

    public function installed(Server $server): ?array
    {
        return (new ExtensionSettings('modpack-switcher'))->forServer($server)->get('installed_pack');
    }

    public function begin(Server $server, array $operation): void
    {
        (new ExtensionSettings('modpack-switcher'))->forServer($server)->set('pending_install', $operation);
    }

    public function complete(Server $server, bool $successful): void
    {
        $store = (new ExtensionSettings('modpack-switcher'))->forServer($server);
        $pending = $store->get('pending_install');
        if ($successful && is_array($pending)) $store->set('installed_pack', $pending['details']);
        $store->forget('pending_install');
    }

    public function provider(Server $server): ?string
    {
        return (new ExtensionSettings('modpack-switcher'))->forServer($server)->get('managed_provider');
    }

    public function mark(Server $server, string $provider): void
    {
        (new ExtensionSettings('modpack-switcher'))->forServer($server)->set('managed_provider', $provider);
    }
}
