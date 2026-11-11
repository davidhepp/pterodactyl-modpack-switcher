# Modpack Switcher

Pterodactyl v2 extension by **Dasky** for browsing and installing Minecraft modpacks from **Modrinth, CurseForge, and Feed The Beast**. It adds a server navigation tab and uses the Panel's existing SDK components, authenticated APIs, server actions, and Wings integration.

## Development environment

- Panel: Pterodactyl `2.0.0-beta.2`, installed from a release archive at `/var/www/pterodactyl`; the Panel directory is not a Git checkout.
- PHP: **8.3** compatibility is required. The development VM runs PHP 8.3.6.
- Node: 22 through nvm; the inspected version is 22.23.4.
- Local SDK: `packages/sdk`, currently `2.0.0-beta.6`.
- Extension source: `extensions/modpack-switcher`.
- Manifest: `extension.json`; author `Dasky`, extension ID `modpack-switcher`, Tailwind prefix `ms`. No custom icon is declared.

Keep changes inside this extension unless an exception is explained first. Do not edit shared eggs or Panel source as part of routine extension development. Database, Redis, Wings, web server, scheduler, and queue services are already configured.

The extension development watcher is already running:

```sh
php artisan p:extension:dev extensions/modpack-switcher --watch --enable
```

Do not start a second watcher or restart services. It publishes successful frontend builds. PHP code runs through the extension provider.

Regular `npm install` previously crashed in npm's peer dependency resolver. When dependency installation is actually needed, `npm install --legacy-peer-deps` worked for this scaffold. Do not reinstall dependencies unnecessarily.

## Administrator settings

Open **Admin → Extensions → Modpack Switcher → Eggs and providers**.

| Setting | Behavior |
| --- | --- |
| Modrinth egg ID | Explicit ID of the installed Modrinth Generic egg. |
| CurseForge egg ID | Explicit ID of the installed CurseForge Generic egg. |
| FTB egg ID | Explicit ID of the installed FTB Server egg. |
| CurseForge global API key | Encrypted backend-only credential for browsing and installation. Leave blank to retain the saved key. |

An egg ID of **0 disables that provider's installation support**. Eggs are never discovered by name automatically. Provider options show unavailable eggs in red and ask users to contact an administrator. Public browsing can still work without an egg configured.

Configured installation eggs must have a nonempty install script, Docker images, and the expected environment variables:

| Provider | Required variables |
| --- | --- |
| Modrinth | `PROJECT_ID`, `VERSION_ID` |
| CurseForge | `PROJECT_ID`, `VERSION_ID`, `API_KEY` |
| FTB | `FTB_MODPACK_ID`, `FTB_MODPACK_VERSION_ID` |

Use the existing eggs provided by the administrator. This extension does not create or rewrite shared eggs. Every Java runtime offered in the dialog comes from the selected egg's Docker images.

## Browsing

The provider dropdown, search, Minecraft-version dropdown, loader filter, sorting, reset controls, cards, and pagination are shared across all three providers. Search and compatibility filters apply when **Search** is submitted; changing sort applies immediately. Changing provider resets pagination and pack selection. Cards include artwork, author, summary, categories, and download/install counts.

The Minecraft dropdown contains recent stable releases and common historical modpack targets. Its curated list is in `src/client/minecraftVersions.ts`; maintain it as new versions become relevant. Specific patch versions remain separate because mod loader compatibility depends on them.

### Modrinth

Uses the public `https://api.modrinth.com/v2/search` API directly from the browser, with credentials omitted. Searches select `project_type:modpack` and exclude `server_side:unsupported`; game versions and loaders use facets. No API key or locally installed Modrinth app is needed. Version metadata comes from `/project/{id}/version`.

This metadata is not a guarantee that a pack will run on a particular server. The referenced generic egg supports Forge, Fabric, and Quilt; its current script has no NeoForge installation branch, so NeoForge-only versions are rejected before installation.

### CurseForge

Requests go through an authenticated server-scoped backend proxy to `https://api.curseforge.com`, using the encrypted global key in `x-api-key`. Neither the key nor raw upstream error bodies are returned by browsing endpoints.

The search endpoint is the canonical **`/v1/mods/search` without a trailing slash**. Query construction follows Prism Launcher's `FlameAPI::searchProjectsURL`: game ID 432, modpack class 4471, index/page size, optional search text, sorting, JSON-array `modLoaderTypes`, and optional `gameVersion`. Always include a loader array, including `[]` for any loader. Loader filtering works independently of Minecraft version.

A previous trailing-slash workaround returned cached data that did not reliably honor filters. Do not reintroduce it. Regression tests cover the canonical path and query construction. Live checks verified different loader results, version filters, sorting, pagination, and empty results for a nonsense search.

Search responses are cached for one minute under a key containing the filters and a hash of the credential. CurseForge caps accessible results at 10,000; pagination respects that cap. Version files load in pages of 50, with **Load older versions** in the dialog. Duplicate server archives are omitted from the selectable release list.

### Feed The Beast

Uses the official public API at `https://api.feed-the-beast.com/v1/modpacks/public`, with a custom identifying User-Agent and no API key.

The backend loads `/modpack/all` and pack metadata, with at most twelve concurrent requests, then caches the compact complete catalog for fifteen minutes. Search, game/loader filters, sorting, and pagination operate on that cached catalog rather than the current page. Combined Minecraft and loader filters must match the **same public release**. Private/archived packs and unusable releases are excluded. Failed loads do not cache an incomplete catalog.

The UI labels FTB's metric as **installs**. Public version lists are cached for one minute. FTB's pack version list does not supply per-version download counts, so the dialog omits that metric instead of showing a misleading zero.

## Current modpack and version switching

The top card shows the current pack, provider, version, Minecraft version, loader, and Java runtime when known.

The source of truth for the current configuration is the server's active egg and stored project/version variables, matched against the administrator's configured egg IDs. This also supports servers installed before current-pack tracking was added. Only the relevant ID variables are read; credentials are excluded. Successful installation snapshots provide stable display metadata. Remote metadata failures fall back to the configured IDs. A variable set to `latest` is labeled **Latest (configured)** rather than claiming to know the exact installed release.

**Switch version** opens the existing pack's version list. It cannot switch to another provider or project. The current version cannot be selected again. The backend rechecks the expected current version to reject stale dialogs.

A version switch changes only the egg's version variable, preserving its Java image, startup command, egg, other variables, and install-script flag. The server is stopped gracefully, its updated configuration is synced to Wings, and a native reinstall is requested. The install script must be enabled. The chosen file policy determines any extension-managed cleanup.

Default policies for version switches:

- **Modrinth / CurseForge:** Keep worlds & server data.
- **FTB:** FTB update — keep everything.

## Installation and file policies

Only server owners and root administrators may install or switch versions. Subusers are not elevated through the administrator-level egg-changing action.

Select a release and, for a new installation, a Java runtime. **Continue** opens the file-policy review. New installations require an explicit policy choice. Native Panel backups are kept separate from server-directory cleanup.

### Keep worlds & server data

This combines the earlier keep-world and replace-modpack options:

- Worlds remain in place throughout installation.
- The extension replaces `mods`, `config`, `defaultconfigs`, `kubejs`, `scripts`, `libraries`, `.fabric`, and `log4jfix`, plus recognized loader/launch files.
- It protects `server.properties`, `whitelist.json`, `ops.json`, `banned-players.json`, `banned-ips.json`, and their supported legacy text equivalents. Small protected files are copied into a temporary directory inside the installer container and restored when the egg script exits, including an ordinary failed exit.
- Unrelated directories are not removed by the extension. The egg itself can still overwrite conflicting files included in a pack.

Recognized launch files include server/Forge/Fabric/Quilt JAR patterns; `.serverjar`; Unix/Windows/JVM argument files; common run/start/launch scripts; Fabric launcher properties; pack manifests; temporary pack ZIPs; and the FTB `serversetup` binary. The authoritative allowlist is `Services/FilePolicy.php`.

If `level-name` places a world inside a directory scheduled for replacement, selective installation is refused. Ambiguous/unsafe world paths require review rather than risking world data. A hard container kill or filesystem failure cannot guarantee restoration; use a manual backup before changing packs.

### Wipe the server

Deletes every server-root entry, including hidden files and worlds, then installs the selected pack. This requires the explicit deletion-confirmation checkbox. The extension uses the Panel's file-delete contract.

### FTB update — keep everything

Available **only when switching versions of the currently configured FTB pack**. It is selected by default for those switches. The extension performs no file cleanup and leaves the FTB egg/server installer to handle its own update process. This option is rejected for new installations and for other providers.

### Backups are manual

There is **no automatic backup checkbox, quota check, backup creation, or backup-wait flow**. Users create and verify backups manually through the Panel before making changes.

## Installation validation and execution

Before modifying settings or files, the backend validates:

- Owner/admin authorization and current server state.
- Configured egg availability and allowed Java image for new installations.
- Project/version ID syntax and provider ownership.
- Provider-specific release availability and download/manifest access.
- The expected source pack/version for version switches.
- File policy restrictions and explicit wipe confirmation.

A shared per-server cache lock serializes install/switch requests. An unfinished operation is not silently replaced by another one. The extension requests a graceful stop, waits up to twenty seconds for Wings to report offline, and does not force-kill the server. A stop timeout leaves files untouched.

New installations use Panel startup/egg-change contracts. Version switches use the single-variable contract. The extension records pending operation details before reinstalling. A server-install-service decorator applies selective cleanup and small-file restoration inside the existing egg's installer. A `RecordsServerInstallResults` decorator records successful metadata and clears pending state after the native Wings result handler. No migrations or core patches are needed.

Failures can leave changed settings and partially installed pack files. Inspect the console and files before retrying. If a request fails ambiguously after submission to Wings, pending state intentionally blocks a second extension operation; inspect the server and finish/retry its native reinstall rather than stacking new destructive requests. Do not disable the extension or change configured eggs during an operation.

### Provider-specific installation details

- **Modrinth:** pins `PROJECT_ID` and `VERSION_ID`, checks server compatibility and a primary `.mrpack`, and enforces the generic egg's supported loaders.
- **CurseForge:** validates project/file ownership, availability, and client/server archive download access before modifying the server. The selected release ID is passed to the existing egg, which resolves an associated server pack or uses its client-manifest fallback.
- **FTB:** validates fresh public pack metadata and the selected version manifest, including its parent pack. Sends exact `FTB_MODPACK_ID` and `FTB_MODPACK_VERSION_ID` values; new installs clear `FTB_SEARCH_TERM` and `FTB_VERSION_STRING` so those cannot override the chosen IDs. Private, archived, mismatched, and empty manifests are rejected.

## CurseForge credential handling

The global key remains encrypted in extension settings. It is not copied into visible startup variables: `API_KEY` receives the non-secret placeholder `managed-by-modpack-switcher`.

For servers marked as extension-managed and still using the configured CurseForge egg, `CurseForgeInstallService` injects the real key into the installation script delivered only to authenticated Wings. The credential is not added to the running server's environment by this extension. Selective preservation wraps that same script without exposing the key to the browser. Other eggs/providers are left alone.

Disabling the extension or changing its configured CurseForge egg removes that installer credential injection. The shared egg itself is not modified.

## Java runtime versus installer

The selected Java image controls the **running server**, not the egg's separate installer container. Version switching preserves the current runtime; change it separately in Startup if the new pack needs another Java version.

The inspected generic Modrinth egg uses a Java 17 installer; CurseForge and FTB eggs use Java 8 installer containers. Administrators must maintain the eggs' installer containers when a pack/loader requires newer installation Java. FTB's supplied script delegates installation to its own server installer. This extension does not rewrite shared scripts or containers.

## Important paths

| Path | Purpose |
| --- | --- |
| `extension.json` | Manifest and server tab declaration. |
| `composer.json` | PHP namespace/autoload map. |
| `src/ModpackSwitcherProvider.php` | Routes, settings, installer decorators, completion hook. |
| `routes/server.php` | Server-scoped authenticated extension endpoints. |
| `src/Http/Controllers/InstallController.php` | Current pack, provider status, installation/version-switch orchestration. |
| `src/Http/Controllers/CurseForgeController.php` | CurseForge browsing and version listing. |
| `src/Http/Controllers/FtbController.php` | FTB browsing and version listing. |
| `src/Services/CurrentPack.php` | Current configuration and display metadata. |
| `src/Services/ManagedInstallations.php` | Scoped pending/completed operation state. |
| `src/Services/FilePolicy.php` | Selective replacement allowlist and installer wrapper. |
| `src/Services/*Release.php` | Provider release validation. |
| `src/Services/ProviderCatalog.php` | Explicit egg configuration and runtime images. |
| `src/Services/FtbCatalog.php` | Cached FTB catalog and compatibility filtering. |
| `src/client/screens/ServerScreen.tsx` | Current-pack card, browser, filters, cards, pagination. |
| `src/client/screens/PackSelectionModal.tsx` | Versions, Java, file policies, install/switch actions. |
| `src/client/installation.ts` | Typed backend calls and installation data contracts. |
| `src/client/styles.css` | Prefixed Tailwind utilities and SDK theme mapping; no preflight reset. |
| `tests/backend.php` | Isolated backend/action/HTTP tests and temporary-directory installer tests. |

Server API prefix: `/api/client/servers/{server}/extensions/modpack-switcher`.

Endpoints: `GET /providers`, `GET /current`, `POST /install`, `GET /curseforge/search`, `GET /curseforge/projects/{project}/versions`, `GET /ftb/search`, and `GET /ftb/projects/{project}/versions`. A scaffold heartbeat also remains at `/api/client/extensions/modpack-switcher/status`.

## Validation

From the Panel root:

```sh
npm run typecheck --prefix extensions/modpack-switcher
npm test --prefix extensions/modpack-switcher
php extensions/modpack-switcher/tests/backend.php
npm run build --prefix extensions/modpack-switcher
php artisan p:extension:doctor extensions/modpack-switcher
```

Frontend tests use the real SDK test host with mocked provider/extension transports. Backend tests use fake models, actions, and HTTP/Wings responses. Preservation tests run a harmless fixture installer under `/tmp` and verify worlds remain in place, only approved files are replaced, and protected files are restored after success and failure. Tests do not stop, wipe, or reinstall a real server.

Read-only live checks have verified provider browsing/filtering, release preflights, and current-pack metadata. End-to-end destructive installations are exercised manually on the disposable Wings server through the UI.

For packaging, build first, run doctor, then use the Panel's `p:extension:pack` command. The existing scaffold includes Vitest, SDK Vite configuration, and OpenAPI-generation tooling. Preserve the SDK test aliases and shared runtime dependencies when changing that tooling.

## References and beta differences

- Official v2 extension guide: https://docs.pterodactyl.io/v2/extensions/building
- Extension reference: https://docs.pterodactyl.io/v2/extensions/reference
- CurseForge API: https://docs.curseforge.com/rest-api/
- Prism's CurseForge request construction: https://github.com/PrismLauncher/PrismLauncher/blob/develop/launcher/modplatform/flame/FlameAPI.cpp
- FTB public API policy: https://www.feed-the-beast.com/blog/p/ftb-api
- FTB egg reference: https://eggs.pterodactyl.io/egg/games-ftb-server/

Prefer local beta implementation/types when current docs differ. This local SDK uses `views` overrides rather than older documented `components.replace` APIs, and the Panel automatically registers extension migration directories. Some Lucide names in newer catalogs are not shipped by this beta; the manifest currently omits its icon. Keep PHP 8.3 compatibility regardless of newer Panel examples.
