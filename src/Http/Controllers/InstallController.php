<?php

declare(strict_types=1);

namespace ModpackSwitcher\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ModpackSwitcher\Services\ModrinthRelease;
use ModpackSwitcher\Services\ProviderCatalog;
use ModpackSwitcher\Services\StopServer;
use ModpackSwitcher\Services\CurseForgeRelease;
use ModpackSwitcher\Services\CurseForgeInstallService;
use ModpackSwitcher\Services\ManagedInstallations;
use ModpackSwitcher\Services\FtbRelease;
use ModpackSwitcher\Services\FilePolicy;
use ModpackSwitcher\Services\CurrentPack;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
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

    public function current(Request $request, Server $server, CurrentPack $packs): JsonResponse
    {
        return new JsonResponse(['installed' => $packs->current($server)]);
    }

    public function install(Request $request, Server $server, ProviderCatalog $catalog, ModrinthRelease $releases, UpdatesServerStartup $startup, ReinstallsServers $reinstall, DeletesFiles $files, VariableValidatorService $variables, StopServer $stop, CurseForgeRelease $curseforge, ManagedInstallations $managed, FtbRelease $ftb, CurrentPack $packs, FilePolicy $policies, UpdatesServerVariable $variableWriter): JsonResponse
    {
        abort_unless($this->canInstall($request, $server), 403, 'Only the server owner or an administrator may switch modpacks.');
        $request->validate(['provider' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])]]);
        $provider = $request->input('provider');
        $egg = match ($provider) { 'ftb' => $catalog->ftbEgg(), 'curseforge' => $catalog->curseforgeEgg(), default => $catalog->modrinthEgg() };
        abort_if($egg === null, 422, 'Provider egg unavailable. Ask an administrator to configure the egg ID.');
        $idRule = $provider !== 'modrinth' ? 'regex:/^[1-9][0-9]{0,9}$/' : 'regex:/^[a-zA-Z0-9]{8}$/';
        $mode = $request->input('mode', 'install');
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['install', 'switch_version'])],
            'provider' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])],
            'project_id' => ['required', 'string', $idRule],
            'version_id' => ['required', 'string', $idRule],
            'expected_version_id' => [$mode === 'switch_version' ? 'required' : 'sometimes', 'string', 'max:64'],
            'java_image' => $mode === 'switch_version' ? ['prohibited'] : ['required', 'string', Rule::in(array_values($egg->docker_images))],
            'file_policy' => ['required', Rule::in(['wipe', 'keep_world', 'ftb_update'])],
            'confirm_wipe' => $request->input('file_policy') === 'wipe' ? ['required', 'accepted'] : ['sometimes', 'boolean'],
        ]);
        abort_if($data['file_policy'] === 'ftb_update' && ($provider !== 'ftb' || $mode !== 'switch_version'), 422, 'FTB update is only available when switching an existing FTB pack version.');
        $lock = Cache::lock('modpack-switcher:install:'.$server->uuid, 300);
        abort_unless($lock->get(), 409, 'Another modpack installation request is in progress.');
        try {
            $server = $server->fresh();
            $server->validateCurrentState();
            abort_if($managed->pending($server) !== null, 409, 'An earlier installation has not finished. Check its console and preserved files before retrying.');
            if ($mode === 'switch_version') {
                $current = $packs->configuration($server);
                abort_unless($current !== null && $current['provider'] === $provider && $current['project_id'] === $data['project_id']
                    && $current['version_id'] === $data['expected_version_id'], 409, 'The current pack changed. Reload before switching versions.');
                abort_if($current['version_id'] === $data['version_id'], 422, 'Choose a different modpack version.');
                abort_if($server->skip_scripts, 422, 'An administrator must enable the egg install script before switching versions.');
            }
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
            if ($mode === 'install') {
                $variables->validate($egg, $environment, User::USER_LEVEL_ADMIN);
            } else {
                $versionVariable = $egg->variables->firstWhere('env_variable', $provider === 'ftb' ? 'FTB_MODPACK_VERSION_ID' : 'VERSION_ID');
                abort_if($versionVariable === null, 422, 'The configured egg has no version variable.');
                Validator::make(['value' => $data['version_id']], ['value' => $versionVariable->rules])->validate();
            }
            $details = $packs->describe($provider, $data['project_id'], $data['version_id']);
            $daemon = Daemon::server($server);
            $stop->stop($server);
            $entries = $daemon->files()->getDirectory('/');
            $names = array_column($entries, 'name');
            $properties = $data['file_policy'] === 'keep_world' && in_array('server.properties', $names, true)
                ? $daemon->files()->getContent('/server.properties', 65536) : null;
            $filePlan = $policies->plan($names, $properties);
            $filePlan['stage'] = '.modpack-switcher-preserve-'.Str::uuid()->toString();
            if ($mode === 'switch_version') {
                $variableWriter->update($server, $versionVariable->id, $data['version_id']);
            } else {
                $server = $startup->update($server, [
                    'egg_id' => $egg->id, 'startup' => $egg->startup,
                    'docker_image' => $data['java_image'], 'skip_scripts' => false,
                    'environment' => $environment,
                ], User::USER_LEVEL_ADMIN);
            }
            $managed->mark($server, $provider);
            $managed->begin($server, ['details' => $details, 'file_policy' => $data['file_policy'], 'egg_id' => $egg->id, 'files' => $filePlan]);
            $server->forceFill(['status' => Server::STATUS_INSTALLING])->saveOrFail();
            try {
                $daemon->sync();
                if ($data['file_policy'] === 'wipe' && $names !== []) {
                    $files->delete($server, '/', $names);
                }
                $reinstall->reinstall($server);
            } catch (Throwable $exception) {
                $server->forceFill(['status' => Server::STATUS_REINSTALL_FAILED])->save();
                report($exception);
                abort(502, 'Installation could not be submitted to Wings. Server settings have changed and files may already be deleted. Check the server console and files before retrying.');
            }
            Activity::event('server:modpack-switcher.install')->subject($server)->property([
                'provider' => $provider, 'project_id' => $data['project_id'], 'version_id' => $data['version_id'], 'file_policy' => $data['file_policy'], 'mode' => $mode, 'java_image' => $data['java_image'] ?? $server->image,
            ])->log();

            return new JsonResponse(['status' => 'installing', 'message' => 'Wings accepted the reinstall. Follow installation in the server console.'], 202);
        } finally {
            $lock->release();
        }
    }
}
