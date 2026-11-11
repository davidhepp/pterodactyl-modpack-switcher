<?php

// Run from the Panel root: php extensions/modpack-switcher/tests/backend.php
// No database writes or real HTTP/Wings calls: models, actions, and transports are fakes.
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$loader = require $root.'/vendor/autoload.php';
putenv('LOG_CHANNEL=stderr');
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$loader->addPsr4('ModpackSwitcher\\', dirname(__DIR__).'/src', prepend: true);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use ModpackSwitcher\Http\Controllers\InstallController;
use ModpackSwitcher\Services\ModrinthRelease;
use ModpackSwitcher\Services\ProviderCatalog;
use ModpackSwitcher\Services\StopServer;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Contracts\Servers\UpdatesServerStartup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\VariableValidatorService;

function check(bool $value, string $message): void {
    if (! $value) throw new RuntimeException($message);
}
function rejects(callable $action, int $status): void {
    try { $action(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        check($e->getStatusCode() === $status, 'Unexpected HTTP status');
        return;
    }
    throw new RuntimeException('Expected rejection');
}

$version = ['project_id' => 'abcdefgh', 'loaders' => ['fabric'], 'files' => [['primary' => true, 'url' => 'https://cdn.modrinth.com/test.mrpack']]];
$project = ['project_type' => 'modpack', 'server_side' => 'required'];
function fakeRelease(array $project, array $version): void {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'api.modrinth.com/v2/project/*' => Http::response($project),
        'api.modrinth.com/v2/version/*' => Http::response($version),
    ]);
}
$release = new ModrinthRelease();
fakeRelease($project, $version);
check($release->validate('abcdefgh', 'ijklmnop')['project_id'] === 'abcdefgh', 'Valid release rejected');
foreach ([
    [$project, [...$version, 'project_id' => 'other']],
    [[...$project, 'server_side' => 'unsupported'], $version],
    [$project, [...$version, 'loaders' => ['neoforge']]],
    [$project, [...$version, 'files' => []]],
] as [$badProject, $badVersion]) {
    fakeRelease($badProject, $badVersion);
    rejects(fn () => $release->validate('abcdefgh', 'ijklmnop'), 422);
}
fakeRelease($project, $version);

#[Pterodactyl\Models\Attributes\Identifiable('serv')]
class MemoryServer extends Server {
    public function fresh($with = []) { return $this; }
    public function save(array $options = []) { return true; }
    public function saveOrFail(array $options = []) { return true; }
    public function validateCurrentState(): void { abort_if($this->status !== null, 409); }
}
$server = new MemoryServer();
$server->forceFill(['id' => 999, 'uuid' => 'test-only', 'owner_id' => 1, 'status' => null]);
$egg = new Egg();
$egg->forceFill(['id' => 16, 'docker_images' => ['Java 21' => 'java:21'], 'startup' => 'java test']);
$egg->setRelation('variables', new Illuminate\Database\Eloquent\Collection());
$catalog = new class($egg) extends ProviderCatalog {
    public function __construct(private Egg $egg) {}
    public function modrinthEgg(): ?Egg { return $this->egg; }
    public function curseforgeEgg(): ?Egg { return $this->egg; }
    public function ftbEgg(): ?Egg { return $this->egg; }
};
$steps = new ArrayObject();
$startup = new class($steps) implements UpdatesServerStartup {
    public function __construct(private ArrayObject $steps) {}
    public function update(Server $server, array $input, int $userLevel = User::USER_LEVEL_USER): Server {
        check($userLevel === User::USER_LEVEL_ADMIN, 'Startup level');
        check(in_array($input['environment']['PROJECT_ID'] ?? $input['environment']['FTB_MODPACK_ID'] ?? '', ['abcdefgh', '285109', '125'], true) && $input['docker_image'] === 'java:21', 'Startup inputs');
        if (isset($input['environment']['API_KEY'])) check($input['environment']['API_KEY'] === ModpackSwitcher\Services\CurseForgeInstallService::KEY_PLACEHOLDER, 'Global key must never enter startup environment');
        if (isset($input['environment']['FTB_MODPACK_ID'])) {
            check($input['environment']['FTB_MODPACK_VERSION_ID'] === '9001' && $input['environment']['FTB_SEARCH_TERM'] === '' && $input['environment']['FTB_VERSION_STRING'] === '', 'Exact FTB version mapping failed');
            check(! isset($input['environment']['PROJECT_ID'], $input['environment']['API_KEY']), 'FTB must not receive other provider variables');
        }
        $this->steps[] = 'startup';
        return $server;
    }
};
$reinstall = new class($steps) implements ReinstallsServers {
    public function __construct(private ArrayObject $steps) {}
    public function reinstall(Server $server): Server { $this->steps[] = 'reinstall'; return $server; }
};
$files = new class($steps) implements DeletesFiles {
    public function __construct(private ArrayObject $steps) {}
    public function delete(Server $server, ?string $root, array $files): void {
        check($root === '/' && $files === ['world', '.hidden'], 'Wipe must include hidden files');
        $this->steps[] = 'wipe';
    }
};
$variables = new class extends VariableValidatorService {
    public function validate(Egg $egg, array $fields = [], int $userLevel = User::USER_LEVEL_USER): Collection { return collect(); }
};
$daemon = new class($steps) {
    public string $state = 'offline';
    public function __construct(private ArrayObject $steps) {}
    public function details() { return ['state' => $this->state]; }
    public function power($action) { check($action === 'stop', 'Must stop gracefully'); $this->steps[] = 'stop'; $this->state = 'offline'; }
    public function files() { return $this; }
    public function getDirectory($path) { return [['name' => 'world'], ['name' => '.hidden']]; }
    public function sync() { $this->steps[] = 'sync'; }
};
Daemon::swap(new class($daemon) {
    public function __construct(private object $daemon) {}
    public function server($server) { return $this->daemon; }
});
Activity::swap(new class {
    public function event($name) { return $this; }
    public function subject($subject) { return $this; }
    public function property($value) { return $this; }
    public function log() {}
});
config(['cache.default' => 'array']);
Cache::clearResolvedInstances();
$request = Request::create('/', 'POST', [
    'provider' => 'modrinth', 'project_id' => 'abcdefgh', 'version_id' => 'ijklmnop',
    'java_image' => 'java:21', 'file_policy' => 'wipe', 'confirm_wipe' => true,
]);
$owner = new User();
$owner->forceFill(['id' => 1, 'root_admin' => false]);
$request->setUserResolver(fn () => $owner);
$controller = new InstallController();
$managed = new class extends ModpackSwitcher\Services\ManagedInstallations {
    public ?string $name = null;
    public function mark(Server $server, string $provider): void { $this->name = $provider; }
    public function provider(Server $server): ?string { return $this->name; }
};
$unusedCfRelease = new ModpackSwitcher\Services\CurseForgeRelease(new ModpackSwitcher\Services\CurseForgeApi(new Pterodactyl\Services\Extensions\ExtensionSettingsRegistry()));
$run = fn () => $controller->install($request, $server, $catalog, $release, $startup, $reinstall, $files, $variables, new StopServer(), $unusedCfRelease, $managed, new ModpackSwitcher\Services\FtbRelease());
$owner->id = 2;
rejects($run, 403);
$owner->id = 1;
$daemon->state = 'running';
foreach (['java_image' => 'untrusted:image', 'file_policy' => 'keep', 'confirm_wipe' => false] as $field => $bad) {
    $original = $request->input($field);
    $request->merge([$field => $bad]);
    try { $run(); throw new RuntimeException('Expected input rejection'); }
    catch (Illuminate\Validation\ValidationException $e) { check(isset($e->errors()[$field]), 'Wrong rejected field'); }
    $request->merge([$field => $original]);
}
check($run()->getStatusCode() === 202, 'Install did not return accepted');
check($steps->getArrayCopy() === ['stop', 'startup', 'sync', 'wipe', 'reinstall'], 'Unexpected operation order');
echo "PASS: release validation, permissions, automatic graceful stop, image/policy/confirmation validation, clean wipe and reinstall order. No real server changes.\n";

$registry = new Pterodactyl\Services\Extensions\ExtensionSettingsRegistry();
$registry->register('modpack-switcher', new class extends Pterodactyl\Services\Extensions\ExtensionSettingsDefinition {
    public function __construct() {}
    public function get(string $key): mixed { return 0; }
});
$explicitCatalog = new ProviderCatalog($registry);
check($explicitCatalog->egg('modrinth') === null, 'Unconfigured Modrinth egg must not be detected');
echo "PASS: unconfigured providers stay disabled.\n";

$cfSettings = new Pterodactyl\Services\Extensions\ExtensionSettingsRegistry();
$keyDefinition = new class extends Pterodactyl\Services\Extensions\ExtensionSettingsDefinition {
    public string $key = 'test-only-key';
    public function __construct() {}
    public function get(string $key): mixed { return $this->key; }
};
$cfSettings->register('modpack-switcher', $keyDefinition);
$cf = new ModpackSwitcher\Http\Controllers\CurseForgeController();
$cfRequest = Request::create('/', 'GET', ['query' => 'Adventure', 'sort' => 'downloads', 'page' => 1, 'minecraft' => '1.21.1', 'loader' => 'neoforge']);
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake(['api.curseforge.com/v1/mods/search?*' => Http::response([
    'data' => [['id' => 123, 'slug' => 'adventure', 'name' => 'Adventure', 'summary' => 'A pack', 'authors' => [['name' => 'Builder']], 'downloadCount' => 100, 'categories' => [['name' => 'Adventure']], 'latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]]]],
    'pagination' => ['index' => 12, 'totalCount' => 15000],
])]);
Cache::store('array')->flush();
$cfResult = $cf->search($cfRequest, $server, $cfSettings)->getData(true);
check($cfResult['hits'][0]['author'] === 'Builder' && $cfResult['total_hits'] === 10000 && $cfResult['offset'] === 12, 'CurseForge normalization or pagination failed');
$upstream = Http::recorded()->first()[0];
check(parse_url($upstream->url(), PHP_URL_PATH) === '/v1/mods/search', 'Use the canonical search path without a trailing slash');
check(array_keys($upstream->data()) === ['gameId', 'classId', 'index', 'pageSize', 'searchFilter', 'sortField', 'sortOrder', 'modLoaderTypes', 'gameVersion'], 'Preserve Prism-compatible query construction');
check($upstream->hasHeader('x-api-key', 'test-only-key'), 'CurseForge key must use a header');
check($upstream['gameId'] === 432 && $upstream['classId'] === 4471 && $upstream['modLoaderTypes'] === '[6]' && $upstream['index'] === 12 && $upstream['sortField'] === 6, 'CurseForge request mapping failed');
check(! str_contains(json_encode($cfResult), 'test-only-key'), 'Key leaked into response');
$keyDefinition->key = '';
rejects(fn () => $cf->search($cfRequest, $server, $cfSettings), 422);
$keyDefinition->key = 'test-only-key';
$cfRequest->merge(['minecraft' => null]);
check($cf->search($cfRequest, $server, $cfSettings)->getStatusCode() === 200, 'Loader-only filtering should be supported');
$cfRequest->merge(['minecraft' => '1.21.1']);
foreach ([403 => 502, 429 => 429, 500 => 502] as $upstreamStatus => $expectedStatus) {
    Cache::store('array')->flush();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake(['api.curseforge.com/*' => Http::response(['message' => 'test-only-key'], $upstreamStatus)]);
    rejects(fn () => $cf->search($cfRequest, $server, $cfSettings), $expectedStatus);
}
echo "PASS: CurseForge filters, response mapping, pagination cap, secret header, missing key, and upstream errors.\n";

Cache::store('array')->flush();
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake(['api.curseforge.com/*' => Http::response([
    'data' => [['id' => 123, 'latestFilesIndexes' => [['gameVersion' => '1.12.2', 'modLoader' => 1]]]],
    'pagination' => ['index' => 0, 'totalCount' => 10],
])]);
rejects(fn () => $cf->search($cfRequest, $server, $cfSettings), 502);
echo "PASS: mismatched upstream page offsets are rejected rather than displayed.\n";

$cfApi = new ModpackSwitcher\Services\CurseForgeApi($cfSettings);
$cfRelease = new ModpackSwitcher\Services\CurseForgeRelease($cfApi);
$clientFile = ['id' => 123456, 'modId' => 285109, 'isAvailable' => true, 'isServerPack' => false, 'serverPackFileId' => 123457];
$serverFile = ['id' => 123457, 'modId' => 285109, 'isAvailable' => true, 'isServerPack' => true];
function fakeCfRelease(array $clientFile, array $serverFile): void {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'api.curseforge.com/v1/mods/285109' => Http::response(['data' => ['gameId' => 432, 'classId' => 4471]]),
        'api.curseforge.com/v1/mods/285109/files/123456' => Http::response(['data' => $clientFile]),
        'api.curseforge.com/v1/mods/285109/files/123457' => Http::response(['data' => $serverFile]),
        'api.curseforge.com/*/download-url*' => Http::response(['data' => 'https://edge.forgecdn.net/example.zip']),
    ]);
}
fakeCfRelease($clientFile, $serverFile);
check($cfRelease->validate('285109', '123456')['id'] === 123456, 'Selected CurseForge file changed');
fakeCfRelease([...$clientFile, 'modId' => 1], $serverFile);
rejects(fn () => $cfRelease->validate('285109', '123456'), 422);
fakeCfRelease($clientFile, [...$serverFile, 'isAvailable' => false]);
rejects(fn () => $cfRelease->validate('285109', '123456'), 422);
fakeCfRelease($clientFile, $serverFile);
$server->status = null;
$request->merge(['provider' => 'curseforge', 'project_id' => '285109', 'version_id' => '123456']);
$before = count($steps);
$keyDefinition->key = '';
rejects(fn () => $controller->install($request, $server, $catalog, $release, $startup, $reinstall, $files, $variables, new StopServer(), $cfRelease, $managed, new ModpackSwitcher\Services\FtbRelease()), 422);
check(count($steps) === $before, 'Missing key must not stop or modify the server');
$keyDefinition->key = 'test-only-key';
check($controller->install($request, $server, $catalog, $release, $startup, $reinstall, $files, $variables, new StopServer(), $cfRelease, $managed, new ModpackSwitcher\Services\FtbRelease())->getStatusCode() === 202, 'CurseForge installation rejected');
check($managed->name === 'curseforge', 'Managed provider marker missing');

$server->egg_id = $egg->id;
$inner = new class($server) extends Pterodactyl\Services\Servers\ServerInstallService {
    public function __construct(private Server $server) {}
    public function info(Request $request, string $uuid): array { return ['container_image' => 'egg:installer', 'entrypoint' => 'bash', 'script' => "#!/bin/bash\necho test\n"]; }
    public function resolveForNode(Request $request, string $uuid): Server { return $this->server; }
};
$delivery = new ModpackSwitcher\Services\CurseForgeInstallService($inner, $catalog, $managed, $cfApi);
$info = $delivery->info($request, 'test-only');
check(str_contains($info['script'], "API_KEY='test-only-key'"), 'Key missing from installer-only delivery');
check($info['container_image'] === 'egg:installer', 'Shared egg installer must be preserved');
$managed->name = 'modrinth';
check(! str_contains($delivery->info($request, 'test-only')['script'], 'API_KEY='), 'Key injected into another provider');
$managed->name = 'curseforge';
$server->egg_id = 999;
check(! str_contains($delivery->info($request, 'test-only')['script'], 'API_KEY='), 'Key injected into another egg');
echo "PASS: CurseForge release ownership, server pack validation, real install orchestration with fakes, and installer-only secret injection.\n";

Cache::store('array')->flush();
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake([
    'api.curseforge.com/v1/mods/285109' => Http::response(['data' => ['gameId' => 432, 'classId' => 4471]]),
    'api.curseforge.com/v1/mods/285109/files?*' => Http::response(['data' => [
        ['id' => 123456, 'isAvailable' => true, 'isServerPack' => false, 'displayName' => 'Test release', 'releaseType' => 2, 'fileDate' => '2026-10-01T00:00:00Z', 'gameVersions' => ['1.21.1', 'NeoForge'], 'downloadCount' => 20],
        ['id' => 123457, 'isAvailable' => true, 'isServerPack' => true],
    ], 'pagination' => ['index' => 0, 'totalCount' => 100]]),
]);
$versions = $cf->versions(Request::create('/', 'GET', ['page' => 0]), $server, '285109', $cfApi)->getData(true);
check(count($versions['versions']) === 1 && $versions['nextPage'] === 1, 'Version list must exclude duplicate server archives and retain pagination');
check($versions['versions'][0]['game_versions'] === ['1.21.1'] && $versions['versions'][0]['loaders'] === ['NeoForge'], 'Version compatibility metadata mismatch');
check(! str_contains(json_encode($versions), 'test-only-key'), 'Version list leaked a credential');
echo "PASS: paginated version list and secret-free compatibility metadata.\n";

Cache::store('array')->flush();
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
$makeFtbPack = fn (int $id, string $name, array $pairs): array => [
    'status' => 'success', 'id' => $id, 'name' => $name, 'slug' => 'pack-'.$id,
    'synopsis' => 'Explore and build', 'installs' => 10, 'plays' => 10, 'released' => 1, 'updated' => 1,
    'authors' => [['name' => 'FTB Team']], 'art' => [['type' => 'square', 'url' => 'https://cdn.feed-the-beast.com/icon.png']],
    'tags' => [['name' => 'Tech']], 'versions' => array_map(fn ($pair) => ['type' => 'release', 'targets' => [
        ['name' => 'minecraft', 'type' => 'game', 'version' => $pair[0]],
        ['name' => $pair[1], 'type' => 'modloader', 'version' => '1'],
    ]], $pairs),
];
$ftbFixtures = ['api.feed-the-beast.com/v1/modpacks/public/modpack/all' => Http::response(['status' => 'success', 'packs' => range(1, 15)])];
for ($id = 1; $id <= 15; $id++) {
    $pack = $makeFtbPack($id, 'Extra '.$id, [['1.12.2', 'forge']]);
    if ($id === 1) $pack = [...$makeFtbPack(1, 'FTB Tech', [['1.20.1', 'forge'], ['1.21.1', 'fabric']]), 'installs' => 200];
    if ($id === 2) $pack = [...$makeFtbPack(2, 'FTB Magic', [['1.21.1', 'neoforge']]), 'updated' => 200, 'released' => 100];
    if ($id === 15) $pack['private'] = true;
    $ftbFixtures['api.feed-the-beast.com/v1/modpacks/public/modpack/'.$id] = Http::response($pack);
}
Http::fake($ftbFixtures);
$ftb = new ModpackSwitcher\Services\FtbCatalog();
$all = $ftb->search('', '', '', 'downloads', 0);
check($all['total_hits'] === 14 && count($all['hits']) === 12 && $all['hits'][0]['title'] === 'FTB Tech', 'FTB catalog counts or installs sort failed');
check(count($ftb->search('', '', '', 'downloads', 1)['hits']) === 2, 'FTB pagination failed');
check($ftb->search('', '1.20.1', 'fabric', 'downloads', 0)['total_hits'] === 0, 'FTB must match game and loader on the same release');
check($ftb->search('', '1.21.1', 'fabric', 'downloads', 0)['total_hits'] === 1, 'FTB combined filter failed');
check($ftb->search('', '', 'neoforge', 'downloads', 0)['hits'][0]['title'] === 'FTB Magic', 'FTB loader-only filtering failed');
check($ftb->search('ftb magic', '', '', 'relevance', 0)['total_hits'] === 1, 'FTB text search failed');
check($ftb->search('', '', '', 'updated', 0)['hits'][0]['title'] === 'FTB Magic', 'FTB updated sort failed');
check(! isset($all['hits'][0]['_targets']), 'Internal FTB filter metadata should not be exposed');
check(Http::recorded()->count() === 16, 'FTB filtering should reuse the cached full catalog');
echo "PASS: FTB full-catalog search, same-release version/loader matching, sorting, pagination, private-pack exclusion, and caching.\n";

Cache::store('array')->flush();
$ftbPublicVersion = ['id' => 9001, 'name' => '1.0.0', 'type' => 'release', 'released' => 1700000000, 'targets' => [
    ['name' => 'minecraft', 'type' => 'game', 'version' => '1.21.1'],
    ['name' => 'neoforge', 'type' => 'modloader', 'version' => '21.1'],
]];
$ftbRelease = new ModpackSwitcher\Services\FtbRelease();
function fakeFtbRelease(array $version, int $parent = 125): void {
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'api.feed-the-beast.com/v1/modpacks/public/modpack/125' => Http::response([
            'status' => 'success', 'id' => 125, 'versions' => [$version, [...$version, 'id' => 9000, 'type' => 'archived'], [...$version, 'id' => 9002, 'private' => true]],
        ]),
        'api.feed-the-beast.com/v1/modpacks/public/modpack/125/9001' => Http::response([
            'status' => 'success', 'id' => 9001, 'parent' => $parent, 'type' => 'release', 'files' => [['name' => 'test.jar']],
        ]),
    ]);
}
fakeFtbRelease($ftbPublicVersion);
$list = $ftbRelease->versions('125');
check(count($list['versions']) === 1 && $list['versions'][0]['id'] === '9001' && $list['versions'][0]['downloads'] === null, 'FTB version list must omit archived/private releases and unknown metrics');
check($ftbRelease->validate('125', '9001')['parent'] === 125, 'Valid FTB manifest rejected');
rejects(fn () => $ftbRelease->validate('125', '9000'), 422);
rejects(fn () => $ftbRelease->validate('125', '9002'), 422);
rejects(fn () => $ftbRelease->validate('125', '9999'), 422);
fakeFtbRelease($ftbPublicVersion, 999);
rejects(fn () => $ftbRelease->validate('125', '9001'), 422);
fakeFtbRelease($ftbPublicVersion);
$server->status = null;
$request->merge(['provider' => 'ftb', 'project_id' => '125', 'version_id' => '9001']);
$before = count($steps);
check($controller->install($request, $server, $catalog, $release, $startup, $reinstall, $files, $variables, new StopServer(), $cfRelease, $managed, $ftbRelease)->getStatusCode() === 202, 'FTB installation rejected');
check($managed->name === 'ftb' && array_slice($steps->getArrayCopy(), $before) === ['startup', 'sync', 'wipe', 'reinstall'], 'FTB must use the shared clean install sequence');
echo "PASS: FTB version selection, public release validation, manifest ownership, exact egg variables, and shared clean install.\n";
