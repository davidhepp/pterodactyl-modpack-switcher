<?php

declare(strict_types=1);

namespace ModpackSwitcher\Services;

class FilePolicy
{
    public function replaceable(string $name): bool
    {
        if (in_array(strtolower($name), [
            'mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'libraries', '.fabric', 'log4jfix',
            '.serverjar', 'unix_args.txt', 'win_args.txt', 'user_jvm_args.txt',
            'run.sh', 'run.bat', 'start.sh', 'start.bat', 'startserver.sh', 'startserver.bat',
            'start-server.sh', 'start-server.bat', 'launch.sh', 'launch.bat',
            'fabric-server-launcher.properties', 'manifest.json', 'client.manifest.json',
            'modrinth.index.json', 'server.zip', 'client.zip', 'serversetup', 'install_profile.json',
        ], true)) return true;
        foreach (['server*.jar', 'start-server*.jar', 'minecraft_server*.jar', '*forge*.jar', 'fabric*.jar', 'quilt*.jar'] as $pattern) {
            if (fnmatch($pattern, strtolower($name))) return true;
        }
        return false;
    }

    public function plan(array $names, ?string $properties = null): array
    {
        foreach ($names as $name) {
            abort_if(! is_string($name) || $name === '' || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, '\\'), 502, 'Wings returned an invalid file name.');
            abort_if(str_starts_with($name, '.modpack-switcher-preserve-'), 409, 'Preserved server data from an earlier install needs recovery before another install can begin.');
        }
        // Never treat a customized world directory as disposable pack content.
        if ($properties !== null && preg_match('/^\s*level-name\s*[=:]\s*(.*?)\r?$/m', $properties, $match)) {
            $world = str_replace('\\ ', ' ', $match[1]);
            abort_if(str_contains($world, '\\') || str_contains($world, "\0") || str_starts_with($world, '/') || in_array('..', explode('/', $world), true), 422, 'The world path in server.properties must be reviewed before preserving server data.');
            $root = explode('/', $world)[0];
            abort_if($root === '' || $root === '.', 422, 'The world must be stored in a separate directory before preserving server data.');
            abort_if($this->replaceable($root), 422, 'Your world is stored in a modpack folder. Move it to a separate world folder before replacing modpack files.');
        }
        return [
            'preserve' => array_values(array_intersect($names, ['server.properties', 'whitelist.json', 'ops.json', 'banned-players.json', 'banned-ips.json', 'white-list.txt', 'ops.txt', 'banned-players.txt', 'banned-ips.txt'])),
            'replace' => array_values(array_filter($names, fn ($name) => $this->replaceable($name))),
        ];
    }

    public function script(string $original, array $plan): string
    {
        $stage = $plan['stage'] ?? '';
        abort_unless(preg_match('/^\.modpack-switcher-preserve-[a-f0-9-]{36}$/', $stage), 500, 'Invalid preservation directory.');
        $preserve = implode(' ', array_map(escapeshellarg(...), $plan['preserve'] ?? []));
        $replace = implode(' ', array_map(escapeshellarg(...), $plan['replace'] ?? []));
        $delimiter = 'MS_SCRIPT_'.str_replace('-', '', substr($stage, strlen('.modpack-switcher-preserve-')));
        $header = <<<'BASH'
#!/bin/bash
set -e
ROOT=/mnt/server
STAGE=$(mktemp -d /tmp/modpack-switcher-preserve.XXXXXX)
preserve=(__PRESERVE__)
replace=(__REPLACE__)
cd "$ROOT"
restore_server_data() {
    result=$?
    trap - EXIT
    set +e
    failed=0
    for name in "${preserve[@]}"; do
        if [ -e "$STAGE/$name" ] || [ -L "$STAGE/$name" ]; then
            rm -rf -- "$ROOT/$name" && cp -Pp -- "$STAGE/$name" "$ROOT/$name" && rm -- "$STAGE/$name" || failed=1
        fi
    done
    if [ "$failed" -ne 0 ] || ! rmdir -- "$STAGE"; then
        echo "Some preserved files remain in $STAGE. Recover them before retrying."
        exit 1
    fi
    exit "$result"
}
trap restore_server_data EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
for name in "${preserve[@]}"; do
    if [ -f "$ROOT/$name" ] || [ -L "$ROOT/$name" ]; then
        cp -Pp -- "$ROOT/$name" "$STAGE/$name"
    fi
done
for name in "${replace[@]}"; do
    rm -rf -- "$ROOT/$name"
done
set +e
BASH;
        $header = str_replace(['__STAGE__', '__PRESERVE__', '__REPLACE__'], [$stage, $preserve, $replace], $header);
        return $header."\nbash <<'".$delimiter."'\n".str_replace("\r\n", "\n", $original)."\n".$delimiter."\nexit \$?\n";
    }
}
