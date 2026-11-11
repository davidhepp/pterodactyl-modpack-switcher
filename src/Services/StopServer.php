<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;

class StopServer
{
    public function stop(Server $server): void
    {
        $daemon = Daemon::server($server);
        if (($daemon->details()['state'] ?? null) === 'offline') {
            return;
        }

        $daemon->power('stop');
        $deadline = microtime(true) + 20;
        do {
            if (($daemon->details()['state'] ?? null) === 'offline') {
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        abort(409, 'The server is still stopping. No files were deleted. Wait for it to stop, then try installing again.');
    }
}
