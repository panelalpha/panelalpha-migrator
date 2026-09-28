# Architecture

Developer notes for WordPress Migrator 1.1.4: what each part of the plugin does and where it lives. How a site actually moves between the two installations is on [Data transfer](data-transfer.md). The operator guide starts at [documentation/README.md](../README.md).

## Entry points

| File | Runs when | Role |
|---|---|---|
| `wordpress-migrator.php` | WordPress loads plugins | Plugin header, constants (`WP_MIGRATOR_VERSION`, `WP_MIGRATOR_PLUGIN_DIR`, `WP_MIGRATOR_PLUGIN_URL`, `WP_MIGRATOR_MIGRATION_DIR`), Composer autoload, `Plugin::getInstance()`. Returns silently when `vendor/autoload.php` is missing. |
| `restore.php` | The source POSTs a piece to the destination | Standalone endpoint outside the REST API. Defines `ABSPATH`, sends CORS headers, runs `RestoreWorker`. Actions: `collect-and-restore`, `status`, `health`. |
| `migrate.php` | CLI, by hand | Drives `remote-sync` and `remote-sync/process-batch` against a source. Not loaded by WordPress. |
| `config.php` | Read on every request | Returns an array: `token`, `mode`, `showButtons`, optional `database`. Rewritten in place by `TokenController` (regex on the `'token' => '…'` line). |
| `branding.php` | Optional, read for the plugin row | `['branding' => ['name' => …, 'url' => …]]`. Added by PanelAlpha (multi-server) to the zip it hands out. |

`Plugin` (singleton) initialises `Config`, creates `wp-content/migrations/` with a deny-all `.htaccess`, and registers four hooks: `admin_menu` (top-level **Migrator**, `manage_options`, `dashicons-update`, position 80), `admin_enqueue_scripts` (only on `toplevel_page_wordpress-migrator`), `rest_api_init` (`RouteProvider::registerWordPressRoutes`), and `plugin_row_meta` (branding).

## Source tree

```text
src/
├── Plugin.php                     bootstrap and WordPress hooks
├── RestoreWorker.php              restore.php dispatcher (destination)
├── Admin/AdminPage.php            renders templates/admin-page.php
├── Api/
│   ├── RouteProvider.php          all REST routes, default scanner exclusions
│   ├── Controllers/               Migration, Backup, Restore, Token, RemoteSyncOrchestration
│   └── Middleware/                RequestValidator, ResponseFormatter (WP_Error on failure)
├── Services/
│   ├── Scanner/                   FileScanner, FileScannerConfig, FileExclusionService, DatabaseScanner
│   ├── Migration/                 MigrationService, MigrationInfoGenerator, MigrationStorageService,
│   │                              MigrationRegistry (destination), MigrationFinalizer,
│   │                              HtaccessService
│   ├── Backup/                    BackupService, DatabaseBackupService, FileBackupService,
│   │                              ChunkHttpSender, ChunkDomainReplacer, ChunkService, ProgressCalculator
│   ├── RemoteSync/                RemoteSyncOrchestrationService, RemoteSyncExecutor
│   └── Restore/RestoreService.php
├── Restore/                       ChunkProcessor, TableRestoreHandler, FileRestoreHandler,
│                                  FilePathConfinement, RestoreHttpHandler, WpConfigParser
├── Dto/                           Migration, FileInfo, TableInfo, TableChunk, …
└── Helpers/                       Config, PathProvider, TokenHelper, BrandingHelper, Database (PDO singleton),
                                   Http (cURL), JsonCompressor, BinarySafeEncoder, CreateStatementNormalizer,
                                   MigrationLock, ExclusionParser, DefaultExclusions, …
```

Namespace `WordpressMigrator\`, PSR-4 from `src/`. `vendor/` is not committed: run `composer install --no-dev` before you zip the plugin, because without `vendor/autoload.php` the plugin loads nothing. The only runtime dependency, `vlucas/phpdotenv`, is not used by the code; PHPUnit is a development dependency. The code needs PHP 8.0 or newer (union types, `mixed`, `str_starts_with`).

## Two ways to run a migration

**Dashboard.** `assets/js/migrator.js` drives everything from the browser on the source: `scan`, `backup/start`, `info` (then the browser itself POSTs the info piece to the destination's `restore.php`, falling back to the destination's `collect-chunk`), then `backup/database/next-chunks-batch` and `backup/files/next-chunks-batch` in a loop, then `finalize`. State for resuming (`wpMigrator_lastMigrationId`, `wpMigrator_lastBackupType`, destination URL, mode) is kept in `localStorage`. The dashboard path does not take the migration lock.

**API.** `RemoteSyncOrchestrationService` does the same on the server: `remote-sync` scans and saves the migration file and takes the lock, each `remote-sync/process-batch` sends up to `batch_size` pieces of the current phase (database, then files), and the call that completes the last phase runs `MigrationFinalizer` and releases the lock. No info piece is sent to the destination in this path. `processBatch` calls `BackupService` directly and never sets `backup_running`, so `remote-sync/status` reports `phase: ready` until the overall status is `completed`. `RemoteSyncExecutor` is constructed but not used by this path. PanelAlpha's client for these endpoints, including `transfer-htaccess`, is `app/packages/api/app/Lib/Apis/Wordpress/WordpressMigratorPlugin.php`.

## Paths and hosting layouts

`PathProvider::getAbsPath()` is the root that is scanned on the source and written on the destination:

- WP Cloud: `WP_CONTENT_DIR` is outside `ABSPATH`, so the parent of `WP_CONTENT_DIR` is used.
- Bitnami: `wp-config.php` or `wp-content` in `ABSPATH` is a symlink that leaves `ABSPATH`, so the symlink target's folder is used.
- Otherwise `ABSPATH`.

`WpConfigParser` finds the destination's database login for `restore.php`, trying in order: constants already defined, a regex over `wp-config.php`, the `database` array in `config.php`, loading `wp-load.php`, and last a temporary `wp-migrator-db-info.php` written to the site root, fetched over HTTP, and deleted. An explicitly empty `$table_prefix` is kept as empty.

## Authentication

- `manage_options` for `scan` and the two `token/*` routes.
- `manage_options` or the site token (`token` parameter or `X-Migrator-Token` header, compared with `hash_equals`) for `backup/*`, `restore/*`, `info`, `finalize`, `list-migrations`, `receive-htaccess`.
- `restore.php` checks `X-Migrator-Token` or `?token=` against `config.php` with `hash_equals` (401 when missing, 403 when wrong); `health` needs no token.
- No check on `remote-sync`, `remote-sync/process-batch`, `remote-sync/status/<id>`, `transfer-htaccess`, `collect-chunk` and `migration-complete`.

The shipped `config.php` has an empty `token`. The Destination dashboard loads `/token/current` and, when none is set (404), calls `/token/generate-and-save` so the address with token appears without a manual click. **Generate New Token** still replaces it after confirmation.

Outgoing requests (`Http::post`) force HTTP/1.1 and turn TLS verification off. With `ip` set, the destination host is pinned to that address with `CURLOPT_RESOLVE` for ports 80 and 443, so the `Host` header and TLS name stay the domain.

## Migration lock

`MigrationLock` writes `wp-content/wp-migrator-lock.json` with the migration ID, a timestamp and the PID. `remote-sync` refuses to start while a lock younger than two hours exists; `force: true` deletes it first. The lock is released when the migration is finalised; a lock older than two hours is treated as released.

## Tests

Unit tests live in `tests/` and run with PHPUnit 11, which needs PHP 8.2 or newer (the plugin itself still runs on 8.0):

```bash
composer install
composer test
```

GitHub Actions runs the same on every push and pull request, on PHP 8.3 (`.github/workflows/unit-tests.yml`).

The tests start neither WordPress nor MySQL. What stands in for them is in `tests/Support/`:

| Helper | Replaces |
|---|---|
| `FakeDatabase` | The `Database` singleton, which otherwise connects to MySQL in its constructor. Backed by an in-memory SQLite PDO or a mock. |
| `RecordingPdo` | A PDO that records every statement, skips the MySQL-only `SET` and `CREATE` statements, and answers `SHOW TABLES` and `SHOW CREATE TABLE` from SQLite. |
| `FakeDestination` | The destination site: a PHP built-in server on `127.0.0.1` that records each request and answers with a status and body the test chooses. |
| `TemporaryDirectory`, `ResetsGlobalState` | A throwaway site or `wp-content/migrations/` folder, and clearing `Config`, the database singleton and `$table_prefix` after each test. |

Code that reads WordPress or plugin constants (`ABSPATH`, `WP_CONTENT_DIR`, `WP_MIGRATOR_*`, `DB_NAME`) is tested in classes marked `#[RunTestsInSeparateProcesses]`, because a constant cannot be undefined once a test defines it.
