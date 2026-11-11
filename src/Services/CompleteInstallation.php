<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

use Illuminate\Http\Request;
use Pterodactyl\Contracts\Servers\RecordsServerInstallResults;
use Pterodactyl\Models\Server;

class CompleteInstallation implements RecordsServerInstallResults
{
    public function __construct(private RecordsServerInstallResults $inner, private ManagedInstallations $managed) {}

    public function record(Request $request, string $uuid, bool $successful, bool $reinstall): Server
    {
        $server = $this->inner->record($request, $uuid, $successful, $reinstall);
        $this->managed->complete($server, $successful);
        return $server;
    }
}
