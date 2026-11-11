<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

class CurseForgeRelease
{
    public function __construct(private CurseForgeApi $api) {}

    public function validate(string $projectId, string $fileId): array
    {
        $project = $this->api->get('/v1/mods/'.$projectId)['data'];
        abort_unless(($project['gameId'] ?? null) === 432 && ($project['classId'] ?? null) === 4471, 422, 'This CurseForge project is not a Minecraft modpack.');
        $file = $this->api->get('/v1/mods/'.$projectId.'/files/'.$fileId)['data'];
        $this->checkFile($file, $projectId);
        abort_if($file['isServerPack'] ?? false, 422, 'Select the modpack release rather than its server archive.');
        $downloadId = $fileId;
        if (! empty($file['serverPackFileId']) && ! ($file['isServerPack'] ?? false)) {
            $downloadId = (string) $file['serverPackFileId'];
            $serverFile = $this->api->get('/v1/mods/'.$projectId.'/files/'.$downloadId)['data'];
            $this->checkFile($serverFile, $projectId);
            abort_unless($serverFile['isServerPack'] ?? false, 422, 'CurseForge returned an invalid server pack.');
        }
        // Validate both downloads before stopping or wiping. The egg also downloads
        // the client manifest to determine which loader it must install.
        foreach (array_unique([$fileId, $downloadId]) as $id) {
            $url = $this->api->get('/v1/mods/'.$projectId.'/files/'.$id.'/download-url')['data'] ?? null;
            abort_unless(is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://'), 422, 'CurseForge does not allow this file to be downloaded through the API.');
        }
        return $file;
    }

    private function checkFile(mixed $file, string $projectId): void
    {
        abort_unless(is_array($file) && (string) ($file['modId'] ?? '') === $projectId && ($file['isAvailable'] ?? false), 422, 'The selected file is unavailable or does not belong to this modpack.');
    }
}
