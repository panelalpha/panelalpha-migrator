# API reference

Every endpoint the plugin adds, with who may call it. To run a migration you need only the four on the source described in [Migrate over the API](migrate-over-the-api.md). The rest are used by the **Migrator** page in the dashboard and by the two sites talking to each other.

All REST endpoints live under `/wp-json/wp-migrator/v1/`. On a site without pretty permalinks, the same endpoint is at `/?rest_route=/wp-migrator/v1/<endpoint>`.

## Who may call

| Access | Meaning |
|---|---|
| Admin | A logged-in WordPress administrator, with the dashboard's REST nonce. |
| Admin or token | An administrator, or a request that carries the site's token as `?token=<token>` or in the `X-Migrator-Token` header. |
| Open | No check. Anyone who can reach the site can call it while the plugin is active. |

## Run a migration (call on the source)

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| POST | `remote-sync` | Open | Scans the source and prepares a migration. Returns `migration_id`. |
| POST | `remote-sync/process-batch` | Open | Sends the next batch of pieces. Finishes the migration after the last one. |
| GET | `remote-sync/status/<migration_id>` | Open | Progress of a migration. |
| POST | `transfer-htaccess` | Open | Sends the source's main `.htaccess` to the destination, overwriting it there. Body: `migration_id`. |

## Used by the dashboard

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| POST | `scan` | Admin | Scans the source and prepares a migration. Body: `destination_url`, `token`, optional `exclusions` and `ip`. |
| POST | `backup/start` | Admin or token | Marks a migration as running. |
| POST | `backup/stop` | Admin or token | Marks a migration as stopped. |
| GET | `backup/status` | Admin or token | Progress of a migration and whether it was interrupted. |
| POST | `backup/database/next-chunk` | Admin or token | Sends one database piece. |
| POST | `backup/database/next-chunks-batch` | Admin or token | Sends a batch of database pieces. |
| GET | `backup/database/progress` | Admin or token | Database progress. |
| POST | `backup/files/next-chunk` | Admin or token | Sends one file piece. |
| POST | `backup/files/next-chunks-batch` | Admin or token | Sends a batch of file pieces. |
| GET | `backup/files/progress` | Admin or token | File progress. |
| POST | `finalize` | Admin or token | Tells the destination the migration is complete. |
| GET | `info` | Admin or token | The saved list of files and tables for a migration, with its summary. |
| GET | `list-migrations` | Admin or token | Migrations saved on this site. |
| GET | `token/current` | Admin | This site's token. |
| POST | `token/generate-and-save` | Admin | Creates a new token and saves it in `config.php`. |

## Received on the destination

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| POST | `/wp-content/plugins/wordpress-migrator/restore.php?action=collect-and-restore` | Token | Receives one piece and writes it into the database or the files at once. |
| GET | `/wp-content/plugins/wordpress-migrator/restore.php?action=status&migration_id=<id>` | Token | What the destination has received and restored. |
| GET | `/wp-content/plugins/wordpress-migrator/restore.php?action=health` | Open | Answers `{"status":"ok","service":"restore-worker"}` when the file runs and can find the database login. |
| POST | `collect-chunk` | Open | Receives the completion message at the end of a migration. |
| POST | `migration-complete` | Open | Marks a received migration as collected. |
| POST | `receive-htaccess` | Admin or token | Writes the `.htaccess` sent by `transfer-htaccess`. |
| POST | `restore/database/next-chunk`, `restore/files/next-chunk` | Admin or token | Restores a stored piece. |
| GET | `restore/progress` | Admin or token | Restore progress. |

`restore.php` is opened directly, not through the WordPress REST API, so the destination must allow PHP files in `wp-content/plugins/wordpress-migrator/` to be opened directly. It finds the database login in `wp-config.php`, and uses the `database` setting in the plugin's `config.php` when that fails: [Settings in config.php](../01-getting-started/install.md#settings-in-configphp).
