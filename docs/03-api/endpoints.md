# API reference

Every endpoint the plugin adds, with who may call it. To run a migration you need only the four on the source described in [Migrate over the API](migrate-over-the-api.md). The rest are used by the **Migrator** page in the dashboard and by the two sites talking to each other.

All REST endpoints live under `/wp-json/panelalpha-migrator/v1/`. On a site without pretty permalinks, the same endpoint is at `/?rest_route=/panelalpha-migrator/v1/<endpoint>`.

## Who may call

| Access | Meaning |
|---|---|
| Admin | A logged-in WordPress administrator, with the dashboard's REST nonce. |
| Admin or token | An administrator, or a request that carries the site's token as `?token=<token>` or in the `X-Migrator-Token` header. |
| Open | No check. Anyone who can reach the site can call it while the plugin is active. |

A request to an Admin or token endpoint with neither answers `401`. This includes `remote-sync`, `remote-sync/process-batch`, `remote-sync/status`, `transfer-htaccess` and `migration-complete`. A site that has no token yet accepts only administrators, so open **Destination** on the page once to create it.

## Run a migration (call on the source)

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| POST | `remote-sync` | Admin or token | Scans the source and prepares a migration. Returns `migration_id`. Accepts `ip` and `insecure_tls`, see [Migrate over the API](migrate-over-the-api.md). |
| POST | `remote-sync/process-batch` | Admin or token | Sends the next batch of pieces. Finishes the migration after the last one. |
| GET | `remote-sync/status/<migration_id>` | Admin or token | Progress of a migration. |
| POST | `transfer-htaccess` | Admin or token | Sends the source's main `.htaccess` to the destination, overwriting it there. Body: `migration_id`. |

## Used by the dashboard

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| POST | `scan` | Admin | Scans the source and prepares a migration. Body: `destination_url`, `token`, optional `exclusions`, `ip` and `insecure_tls`. `insecure_tls` (default `false`) skips the HTTPS certificate check, and only works together with `ip`. |
| POST | `backup/start` | Admin or token | Marks a migration as running and takes the source's migration lock. Refused while another migration holds it. |
| POST | `backup/stop` | Admin or token | Marks a migration as stopped and releases the lock. |
| GET | `backup/status` | Admin or token | Progress of a migration and whether it was interrupted. |
| POST | `backup/database/next-chunk` | Admin or token | Sends one database piece. |
| POST | `backup/database/next-chunks-batch` | Admin or token | Sends a batch of database pieces. |
| GET | `backup/database/progress` | Admin or token | Database progress. |
| POST | `backup/files/next-chunk` | Admin or token | Sends one file piece. |
| POST | `backup/files/next-chunks-batch` | Admin or token | Sends a batch of file pieces. |
| GET | `backup/files/progress` | Admin or token | File progress. |
| POST | `finalize` | Admin or token | Tells the destination the migration is complete, and releases the lock once the destination confirms. |
| GET | `info` | Admin or token | The saved list of files and tables for a migration, with its summary. |
| GET | `list-migrations` | Admin or token | Migrations saved on this site. |
| GET | `token/current` | Admin | This site's token. |
| POST | `token/generate-and-save` | Admin | Creates a new token and saves it in the WordPress database. |

## Received on the destination

The first four are called by the source. They accept only the destination token in the `X-Migrator-Token` header. A token in the address is refused, so it does not end up in server logs. A wrong or missing token answers `401`.

| Method | Endpoint | Access | What it does |
|---|---|---|---|
| GET | `receive/check` | Token header | Tells the source that this destination can receive a migration. Returns the plugin and WordPress versions and the free disk space. |
| POST | `receive` | Token header | Receives one piece. Database pieces go into temporary tables, file pieces into a work folder. The live site is not changed yet. |
| POST | `receive/finalize` | Token header | Moves the files into place and swaps the new tables in. Answers `202` with `finalized: false` while another call is still finishing. |
| GET | `receive/status` | Token header | What the destination has received for a migration. Query: `migration_id`. |
| POST | `migration-complete` | Admin or token | Marks a received migration as collected. |
| POST | `receive-htaccess` | Admin or token | Writes the `.htaccess` sent by `transfer-htaccess`. |

The destination is called through the WordPress REST API only, so a security plugin or firewall that blocks `/wp-json/panelalpha-migrator/v1/` on the destination stops a migration.
