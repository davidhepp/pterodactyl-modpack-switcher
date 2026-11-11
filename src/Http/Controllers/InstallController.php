<?php

declare(strict_types=1);

namespace ModpackSwitcher\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use ModpackSwitcher\Services\ModrinthRelease;
use ModpackSwitcher\Services\ProviderCatalog;
use ModpackSwitcher\Services\StopServer;
use ModpackSwitcher\Services\CurseForgeRelease;
use ModpackSwitcher\Services\CurseForgeInstallService;
use ModpackSwitcher\Services\ManagedInstallations;
use ModpackSwitcher\Services\FtbRelease;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Contracts\Servers\UpdatesServerStartup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\VariableValidatorService;
use Throwable;

class InstallController
{
    private function canInstall(Request $request, Server $server): bool
    {
        // Egg changes are privileged: do not elevate subusers through the admin action.
        return $request->user()->root_admin || $request->user()->id === $server->owner_id;
    }

    public function providers(Request $request, Server $server, ProviderCatalog $catalog): JsonResponse
    {
        return new JsonResponse(['providers' => $catalog->providers(), 'can_install' => $this->canInstall($request, $server)]);
    }

    public function install(Request $request, Server $server, ProviderCatalog $catalog, ModrinthRelease $releases, UpdatesServerStartup $startup, ReinstallsServers $reinstall, DeletesFiles $files, VariableValidatorService $variables, StopServer $stop, CurseForgeRelease $curseforge, ManagedInstallations $managed, FtbRelease $ftb): JsonResponse
    {
        abort_unless($this->canInstall($request, $server), 403, 'Only the server owner or an administrator may switch modpacks.');
        $request->validate(['provider' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])]]);
        $provider = $request->input('provider');
        $egg = match ($provider) { 'ftb' => $catalog->ftbEgg(), 'curseforge' => $catalog->curseforgeEgg(), default => $catalog->modrinthEgg() };
        abort_if($egg === null, 422, 'Provider egg unavailable. Ask an administrator to configure the egg ID.');
        $idRule = $provider !== 'modrinth' ? 'regex:/^[1-9][0-9]{0,9}$/' : 'regex:/^[a-zA-Z0-9]{8}$/';
        $data = $request->validate([
            'provider' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])],
            'project_id' => ['required', 'string', $idRule],
            'version_id' => ['required', 'string', $idRule],
            'java_image' => ['required', 'string', Rule::in(array_values($egg->docker_images))],
            'file_policy' => ['required', Rule::in(['wipe'])],
            'confirm_wipe' => ['required', 'accepted'],
        ]);
        $lock = Cache::lock('modpack-switcher:install:'.$server->uuid, 300);
        abort_unless($lock->get(), 409, 'Another modpack installation request is in progress.');
        try {
            $server = $server->fresh();
            $server->validateCurrentState();
            if ($provider === 'ftb') {
                $ftb->validate($data['project_id'], $data['version_id']);
            } elseif ($provider === 'curseforge') {
                $curseforge->validate($data['project_id'], $data['version_id']);
            } else {
                $releases->validate($data['project_id'], $data['version_id']);
            }
            $environment = $egg->variables->mapWithKeys(fn ($variable) => [$variable->env_variable => $variable->default_value])->all();
            if ($provider === 'ftb') {
                $environment['FTB_MODPACK_ID'] = $data['project_id'];
                $environment['FTB_MODPACK_VERSION_ID'] = $data['version_id'];
                $environment['FTB_SEARCH_TERM'] = '';
                $environment['FTB_VERSION_STRING'] = '';
            } else {
                $environment['PROJECT_ID'] = $data['project_id'];
                $environment['VERSION_ID'] = $data['version_id'];
            }
            if ($provider === 'curseforge') {
                $environment['API_KEY'] = CurseForgeInstallService::KEY_PLACEHOLDER;
            }
            $variables->validate($egg, $environment, User::USER_LEVEL_ADMIN);
            $daemon = Daemon::server($server);
            $stop->stop($server);
            $entries = $daemon->files()->getDirectory('/');
            $names = array_column($entries, 'name');
            foreach ($names as $name) {
                abort_if(! is_string($name) || $name === '' || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, '\\'), 502, 'Wings returned an invalid file name. No files were removed.');
            }
            // Prepare the egg/environment before destructive work. No request-supplied
            // egg ID, startup command, arbitrary environment, or Docker image is trusted.
            $server = $startup->update($server, [
                'egg_id' => $egg->id, 'startup' => $egg->startup,
                'docker_image' => $data['java_image'], 'skip_scripts' => false,
                'environment' => $environment,
            ], User::USER_LEVEL_ADMIN);
            // Persist an operation marker before touching Wings. A failed request must
            // not be silently retried by the client after files may have been deleted.
            $managed->mark($server, $provider);
            $server->forceFill(['status' => Server::STATUS_INSTALLING])->saveOrFail();
            try {
                $daemon->sync();
                if ($names !== []) {
                    $files->delete($server, '/', $names);
                }
                $reinstall->reinstall($server);
            } catch (Throwable $exception) {
                $server->forceFill(['status' => Server::STATUS_REINSTALL_FAILED])->save();
                report($exception);
                abort(502, 'Installation could not be submitted to Wings. Server settings have changed and files may already be deleted. Check the server console and files before retrying.');
            }
            Activity::event('server:modpack-switcher.install')->subject($server)->property([
                'provider' => $provider, 'project_id' => $data['project_id'], 'version_id' => $data['version_id'], 'file_policy' => 'wipe', 'java_image' => $data['java_image'],
            ])->log();

            return new JsonResponse(['status' => 'installing', 'message' => 'Wings accepted the reinstall. Follow installation in the server console.'], 202);
        } finally {
            $lock->release();
        }
    }
}
