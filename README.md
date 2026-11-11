# Modpack Switcher

A Pterodactyl panel extension, scaffolded by `p:extension:make`.

## Layout

- `extension.json`: the manifest; `provider` is the boot entry point.
- `src/`: PSR-4 PHP (`ModpackSwitcher\`), booted through `src/ModpackSwitcherProvider.php`.
  Runs fully trusted, in-process, with the same middleware stacks core uses.
- `composer.json`: the PSR-4 map the panel and your editor read. Composer
  dependencies go here too; the panel loads `vendor/autoload.php` when it exists.
- `routes/client.php`: mounted at `/api/client/extensions/modpack-switcher`. Add
  `routes/admin.php` / `routes/application.php` for the other API surfaces.
- `database/migrations/`: extension-owned migrations. Prefix tables with
  `ext_modpack-switcher_` by convention.

## Frontend

The UI entry (`src/client/index.tsx`) is compiled against `@pterodactyl/sdk` to a
single prebuilt ESM file — the panel dynamic-imports it at boot and resolves
React, TanStack Query, and the SDK through its import map, so the bundle stays
tiny and shares the panel's runtime.

```sh
npm install
npm run build        # or: npm run dev (rebuild on change)
npm run typecheck
npm test
```

`dist/client.js` must exist before the extension is enabled; it is published to
`/assets/extensions/modpack-switcher/` on install/enable.

A `file:` SDK dependency is a link into the panel checkout. `preserveSymlinks` in
`tsconfig.json` makes the SDK's types use this package's React and TanStack Query
instead of the panel's copies; keep it.


Screen metadata is declared in `extension.json` under `ui.screens`. The client entry registers the implementation with `screens.register('main', importer)`. Keep both IDs in sync. The panel renders while extensions load; errors and loading are isolated per mount. Reload the panel after enabling, disabling, updating, or changing extension configuration.

Tests use the real SDK with `createExtensionTestHost`, including the host query cache,
router, permissions, and websocket events. CI runs types, tests, and a production build.

To generate a client for this extension, export its routes from the panel after Scribe
generation, then run the generator in this package:

```sh
php /path/to/panel/scripts/generate-extension-openapi.php modpack-switcher /path/to/panel/storage/app/private/scribe/openapi.yaml /path/to/modpack-switcher/openapi.yaml
npm run api:generate
```

The generated client uses `@pterodactyl/sdk/api` and the panel's shared authentication
and transport. Commit generated sources and the OpenAPI input with your extension.
Use `p:extension:doctor`, `p:extension:dev --watch`, and `p:extension:pack` from the panel
for validation, successful-build publishing, and runtime-only packaging.

`src/client/styles.css` compiles Tailwind with this extension's own prefix, `ms`
(`ui.prefix` in `extension.json`), and without resetting the panel, so its utilities never
collide with the panel's or another extension's: write `ms:flex`, `ms:lg:hidden`,
`ms:hover:bg-accent`, `ms:mt-4!` and `@apply ms:flex`. Unprefixed classes are not in
the extension's stylesheet, and `p:extension:doctor` and the installer reject a build whose
prefix is not the declared one. The SDK theme maps the same live tokens as core, so use
`ms:bg-card`, `ms:text-foreground`, and `ms:border-border` instead of palette
colors. Pass `prefix: 'ms'` to the SDK test hosts when a test asserts merged classes.
CSS loads before extension setup.

Run `php artisan p:extension:types /path/to/modpack-switcher` to generate literal screen ids and
configuration keys after loading the provider. Declare frontend settings with
`->frontend()->frontendType('string')` and add `->public()` to those guests may read; use
`defineConfiguredExtension` with a runtime
parser and the generated types. Secret definitions use `->secret()` and stay backend-only.
## Develop

```sh
php artisan p:extension:dev /path/to/modpack-switcher --enable
```

This builds the extension, installs it from where it is and enables it; `--watch` republishes each
change. Package for distribution with `p:extension:pack`, after building `dist/` and, if
the extension has Composer dependencies, running
`composer install --no-dev --optimize-autoloader`:

```sh
php artisan p:extension:pack /path/to/modpack-switcher
```

## Provider configuration and installs

In Admin → Extensions → Modpack Switcher → Eggs and providers:

- Set each provider's installed egg ID explicitly. `0` disables the provider; eggs are never detected automatically.
- Set the encrypted global CurseForge API key here. It is backend-only and used by the CurseForge browsing proxy. CurseForge uses the configured existing egg for installation.

Modrinth, CurseForge, and FTB installations are limited to server owners and root administrators. Select a pack version and an egg-provided Java runtime, then explicitly confirm the clean wipe. The extension requests a graceful stop and waits for Wings to report the server offline before changing settings or deleting files. A stop timeout leaves files untouched. Keep-files installation is disabled. The extension validates the version with its provider, switches egg/startup/environment through Panel contracts, deletes the root entries (including hidden files), and asks Wings to reinstall. It never starts an installation automatically. A failure after preparation can leave the new settings and deleted files in place; inspect the console and files before retrying. Native Panel backups are separate from this wipe.

The generic egg currently supports Forge, Fabric, and Quilt; NeoForge versions are rejected. The chosen Java image is the runtime image. The egg independently defines its installation container (the referenced official egg uses Java 17); an administrator must maintain that container for packs whose installer needs a newer JDK. The extension does not rewrite shared egg scripts or images.

Validation from the Panel root:

```sh
npm run typecheck --prefix extensions/modpack-switcher
npm test --prefix extensions/modpack-switcher
php extensions/modpack-switcher/tests/backend.php
npm run build --prefix extensions/modpack-switcher
php artisan p:extension:doctor extensions/modpack-switcher
```

Backend tests use fake models, actions and HTTP/Wings transports. They do not wipe or reinstall a real server. The existing development watcher handles publishing.


## CurseForge browsing

Choose CurseForge in the provider dropdown to use the same search, sorting, Minecraft version, loader filtering, and pagination as the Modrinth browser. Pack selection opens the shared version and Java selection dialog. Versions load in pages with a Load older versions action. Continue leads to the same explicit clean-wipe confirmation used by Modrinth.

The global API key must be configured in extension settings. Requests go through the authenticated server-scoped `GET /curseforge/search` endpoint; credentials and upstream error bodies are never returned to the browser. Browsing does not require an egg or installation privileges. Search responses are cached for one minute, keyed by filters and a hash of the configured credential. Loader and Minecraft version filters can be used independently. CurseForge caps accessible search results at 10,000. Requests use the canonical search endpoint and JSON-array `modLoaderTypes`, as used by Prism Launcher; the trailing-slash route must not be used because its caching can ignore filters.


CurseForge installs validate project/file ownership, file availability, and client/server archive download access before stopping or wiping the server. The selected release file ID is passed to the configured CurseForge egg; that existing script resolves an associated server archive, or uses its client-manifest fallback. The egg's startup, scripts, and installation container remain unchanged.

The global CurseForge key is not copied into server startup variables. The egg's `API_KEY` variable receives a non-secret placeholder. For servers marked as managed by this extension and still using the configured CurseForge egg, a Laravel service decorator injects the key into the installation script returned only to authenticated Wings. Disabling the extension or changing its configured egg removes that credential injection. No shared egg or Panel files are edited.

The configured CurseForge egg currently uses a Java 8 installation container. Administrators must maintain that egg's installer image for modern Forge/NeoForge releases; choosing Java 21 as the server runtime does not change the egg's installer image.

## FTB browsing

FTB uses the official public API at `https://api.feed-the-beast.com/v1/modpacks/public`. No API key is required. Requests identify this extension with a custom User-Agent, as requested in FTB's API policy: https://www.feed-the-beast.com/blog/p/ftb-api.

The authenticated server-scoped `GET /ftb/search` endpoint loads the `/modpack/all` index and pack manifests with at most twelve concurrent requests, then caches a compact catalog for fifteen minutes. Search, Minecraft version, loader, sorting, and pagination operate on the complete cached catalog. Combined game/loader filters must match the same public, non-archived release. Private packs and packs without usable public releases are excluded. If loading fails, no incomplete catalog is cached.

The shared UI labels FTB's metric as installs. Selecting a pack opens the shared version, Java runtime, and clean-wipe installation dialog. An explicitly configured FTB egg is required for installation; browsing does not require an egg.


FTB installation uses the existing configured FTB Server egg without changing its shared script, startup command, or installer container. The extension sends exact `FTB_MODPACK_ID` and `FTB_MODPACK_VERSION_ID` values, and clears `FTB_SEARCH_TERM` and `FTB_VERSION_STRING`. Fresh public pack metadata and the selected version manifest are validated before stopping the server; private, archived, mismatched, and empty manifests are rejected. Public version lists are cached for one minute. FTB does not supply per-version download counts in this list, so the UI omits that metric instead of showing zero.
