# Modpack Switcher

Browse, install, and update Minecraft modpacks from **Modrinth, CurseForge, and Feed The Beast** in Pterodactyl v2.

By [davidhepp](https://github.com/davidhepp).

## Installation

Download the `.pteroext` package from [Releases](https://github.com/davidhepp/pterodactyl-modpack-switcher/releases/latest). In the Panel, open **Admin → Extensions → Install**, upload the package, and enable it.

Alternatively, run this from the Panel directory:

```sh
php artisan p:extension:install /path/to/modpack-switcher-1.0.0.pteroext --enable
```

The release package includes the compiled frontend. You do not need Node.js to install it. For CLI installation, follow the [Panel's file ownership instructions](https://docs.pterodactyl.io/v2/extensions#installing-extensions).

### Requirements

- Pterodactyl v2 with extension support and a working Wings node.
- PHP 8.3 or later, within the manifest's `^8.3` constraint.
- SDK `>=2.0.0-beta.6 <3.0`.
- Existing provider eggs for installation.

Tested with Panel **2.0.0-beta.2**, SDK **2.0.0-beta.6**, and PHP **8.3.6**. This extension does not support Pterodactyl v1.

## Configuration

Open **Admin → Extensions → Modpack Switcher → Eggs and providers**.

| Setting | What to enter |
| --- | --- |
| Modrinth egg ID | The ID of your installed Modrinth Generic egg. |
| CurseForge egg ID | The ID of your installed CurseForge Generic egg. |
| FTB egg ID | The ID of your installed FTB Server egg. |
| CurseForge global API key | Your CurseForge API key. It is stored encrypted. Leave the field blank to keep a saved key. |

Egg IDs must be set manually. **0 disables installation for that provider.** Unavailable eggs appear in red in the provider dropdown. Browsing does not require an egg; CurseForge browsing still requires its API key.

Eggs need an install script, Docker images, and these variables:

| Provider | Required variables |
| --- | --- |
| Modrinth | `PROJECT_ID`, `VERSION_ID` |
| CurseForge | `PROJECT_ID`, `VERSION_ID`, `API_KEY` |
| FTB | `FTB_MODPACK_ID`, `FTB_MODPACK_VERSION_ID` |

The extension uses your existing eggs without editing their shared scripts or settings.

## Using the switcher

Open a server's **Modpack Switcher** tab. Choose a provider, search for a pack, and filter by Minecraft version or loader. Submit **Search** to apply filters; sorting updates immediately.

Select a pack to view its releases, including dates, release types, and compatibility details. Choose a version and Java runtime, then click **Continue** to choose how existing files are handled. Only server owners and root administrators can install packs or switch versions.

The current-pack card shows the configured pack, version, Minecraft version, loader, and Java runtime. **Switch version** opens that pack's releases. A version switch retains the egg, startup command, Java image, and other variables; it updates the version ID and requests a reinstall.

The server stops gracefully before changes begin. If it has not stopped within twenty seconds, the operation ends without changing files.

### Existing files

**Back up your server manually before changing packs or versions.** Modpack Switcher does not create backups automatically.

| Option | What happens |
| --- | --- |
| **Keep worlds & server data** | Replaces modpack folders and launch files. Worlds stay in place. Player lists and `server.properties` are protected. |
| **Wipe the server** | Deletes all server files, including worlds and hidden files, then installs the pack. Requires confirmation. Native Panel backups are separate and are not deleted. |
| **FTB update — keep everything** | Runs the existing FTB egg's update process without extension-managed cleanup. Available only for FTB version switches. |

Version switches default to **Keep worlds & server data** for Modrinth and CurseForge, and **FTB update** for FTB. New installations require a choice.

The preservation option replaces `mods`, `config`, `defaultconfigs`, `kubejs`, `scripts`, `libraries`, `.fabric`, and `log4jfix`, along with recognized server/loader JARs, argument files, launch scripts, manifests, and installer files. The full list is in [FilePolicy.php](src/Services/FilePolicy.php).

It restores `server.properties`, `whitelist.json`, `ops.json`, `banned-players.json`, `banned-ips.json`, and supported legacy equivalents after the installer exits, including a normal failed exit. Other directories are left in place, although pack installers can overwrite matching files. A hard container kill or filesystem failure can interrupt restoration. Worlds stored inside a folder scheduled for replacement must be moved before using this option.

### Java versions

The Java selector changes the server's **runtime image**, using the images available in its egg. It does not change the egg's installer container. Version switches keep the current runtime; change it on the Startup page if an update needs another Java version.

The referenced Modrinth egg uses Java 17 for installation; the CurseForge and FTB eggs use Java 8 installer containers. An administrator may need to update those containers for newer loaders. The referenced Modrinth egg supports Forge, Fabric, and Quilt, but does not install NeoForge-only packs.

### Failed installations

Check the console and server files before retrying. A failed install can leave changed settings and partially installed files.

If Wings may have accepted a request but the Panel lost the response, the extension blocks another operation until the pending install is resolved. Check the server and finish or retry its native reinstall. Keep the extension enabled and its egg configuration unchanged while an operation is running.

## Provider details

### Modrinth

Modrinth searches use its public API directly from the browser without Panel credentials. No API key or Modrinth app is required. Client-only packs marked unsupported for servers are excluded. Pack metadata still cannot guarantee compatibility with a particular server.

### CurseForge

CurseForge requests run through the Panel using the global API key. Search supports Minecraft and loader filters independently. Results are cached for one minute, with a maximum of 10,000 accessible results. Versions load in pages of 50; use **Load older versions** to see more.

The search proxy uses `/v1/mods/search` without a trailing slash and a JSON-array `modLoaderTypes` parameter, following [Prism Launcher's request format](https://github.com/PrismLauncher/PrismLauncher/blob/develop/launcher/modplatform/flame/FlameAPI.cpp). The trailing-slash route returned cached results that did not reliably honor filters during testing.

Before installation, the extension checks file ownership, availability, and download access. The existing egg resolves a linked server archive or uses its client-manifest fallback.

The global key is not stored in visible server startup variables. `API_KEY` receives a placeholder; the actual key is injected into the install script sent to authenticated Wings for extension-managed CurseForge servers. Disabling the extension or changing its configured egg removes that injection.

### Feed The Beast

FTB uses its public API without a key. The Panel loads the full catalog with up to twelve concurrent requests and caches it for fifteen minutes. Filtering happens before pagination, with Minecraft version and loader matched against the same release. Private and archived releases are excluded.

Cards show installs rather than downloads. FTB's version list does not provide per-release download counts, so those counts are omitted.

Installation passes the selected IDs to `FTB_MODPACK_ID` and `FTB_MODPACK_VERSION_ID`. New installations clear the search and version-name fields. Fresh metadata and the version manifest are checked before installation.

## Development

Place the repository at `extensions/modpack-switcher` inside a development Panel with its frontend dependencies installed. The extension uses the Panel's local `packages/sdk`.

From the Panel root, prepare the SDK:

```sh
npm run sdk:generate
npm run sdk:testing
```

Install the extension's frontend dependencies:

```sh
cd extensions/modpack-switcher
npm install --legacy-peer-deps
```

`--legacy-peer-deps` avoids a peer-resolution crash encountered with npm 10. For development builds and publishing, run this from the Panel root:

```sh
php artisan p:extension:dev extensions/modpack-switcher --watch --enable
```

### Checks and packaging

Run from the Panel root:

```sh
npm run typecheck --prefix extensions/modpack-switcher
npm test --prefix extensions/modpack-switcher
php extensions/modpack-switcher/tests/backend.php
npm run build --prefix extensions/modpack-switcher
php artisan p:extension:doctor extensions/modpack-switcher
php artisan p:extension:pack extensions/modpack-switcher --output=modpack-switcher-1.0.0.pteroext
```

Frontend tests use the SDK test host with mocked transports. Backend tests use fake models, actions, and HTTP/Wings responses. Preservation tests run a fixture installer under `/tmp`; the tests do not stop or reinstall a real server.

Release packages contain the manifest, Composer metadata, routes, PHP runtime, and compiled frontend assets. Tests, dependencies, source maps, and frontend source files are excluded from the published bundle.

### Code layout

| Path | Purpose |
| --- | --- |
| `extension.json` | Manifest and server tab. |
| `src/ModpackSwitcherProvider.php` | Routes, settings, and installation hooks. |
| `src/Http/Controllers/` | Browsing, versions, current pack, and install endpoints. |
| `src/Services/CurrentPack.php` | Reads current egg variables and resolves display metadata. |
| `src/Services/ManagedInstallations.php` | Pending operations and completed-install snapshots. |
| `src/Services/FilePolicy.php` | File replacement rules and preservation wrapper. |
| `src/Services/*Release.php` | Provider release validation. |
| `src/Services/FtbCatalog.php` | FTB catalog cache and filtering. |
| `src/client/screens/` | Browser and installation dialog. |
| `src/client/minecraftVersions.ts` | Curated Minecraft filter options. |
| `tests/backend.php` | Backend and preservation tests. |

Server endpoints live under `/api/client/servers/{server}/extensions/modpack-switcher`: `/providers`, `/current`, `/install`, and the provider search/version routes in [routes/server.php](routes/server.php).

Current-pack information comes from the active egg and its project/version variables, with snapshots from completed installs for display metadata. A `latest` variable is shown as **Latest (configured)** because it does not identify an exact installed release. Per-server locks prevent overlapping operations, and version switches check the expected current version before proceeding.

The frontend uses the SDK's shared components and `ms`-prefixed Tailwind classes. The local beta SDK uses `views` overrides, and the Panel registers extension migration directories automatically; check local types when newer documentation differs.

## References

- [Pterodactyl v2 extension guide](https://docs.pterodactyl.io/v2/extensions/building)
- [Testing and packaging](https://docs.pterodactyl.io/v2/extensions/testing-and-packaging)
- [CurseForge API](https://docs.curseforge.com/rest-api/)
- [FTB API policy](https://www.feed-the-beast.com/blog/p/ftb-api)
- [FTB Server egg](https://eggs.pterodactyl.io/egg/games-ftb-server/)
