# Migrate over the API

The same migration can run without a browser. Another program, such as PanelAlpha, a script, or the bundled `migrate.php`, asks the source to prepare the migration, then asks it again and again to send the next batch until the whole site is on the destination. Nothing waits on an open tab, and each request is short.

This page is for whoever writes or runs that program. All requests go to the **source** site's WordPress REST API, under `/wp-json/wp-migrator/v1/`. The examples use `https://old-site.example.com` for the source and `https://new-site.example.com` for the destination.

## Before you start

- The plugin is active on both sites, and you have the destination's address with its token: [Install the plugin](../01-getting-started/install.md#create-a-new-token) and [Copy the address from the destination](../01-getting-started/migrate-a-site.md#1-copy-the-address-from-the-destination).
- **The migration replaces the destination site.** See [What gets moved](../02-guides/what-gets-moved.md).
- One migration at a time per source. A second start is refused while one is running, unless you pass `force`.
- The migration endpoints on the source do not ask for a login or a token. Anyone who can reach the source can call them while the plugin is active. Remove the plugin when you are done.

## Start the migration

The source lists every file and table, saves that list, and answers with a migration ID.

```bash
curl -X POST "https://old-site.example.com/wp-json/wp-migrator/v1/remote-sync" \
  -H "Content-Type: application/json" \
  -d '{
    "destination_url": "https://new-site.example.com?token=<token>",
    "backup_database": true,
    "backup_files": true
  }'
```

| Field | Required | What it does |
|---|---|---|
| `destination_url` | yes | The destination's address with `?token=`, as shown on its **Migrator** page. The token must be 64 hexadecimal characters. |
| `backup_database` | no | Send the database. Default `true`. |
| `backup_files` | no | Send the files. Default `true`. At least one of the two must be `true`. |
| `exclusions` | no | Extra items to leave out, separated by commas or new lines, such as `"*.log,node_modules"`. The rules are on [What gets moved](../02-guides/what-gets-moved.md#leave-something-out). |
| `ip` | no | The destination server's IP address, for a domain that does not point at it yet. Requests go to this IP address and still name the domain, and the database gets the domain. |
| `force` | no | `true` removes the lock left by an earlier migration on this source, so this one can start. The earlier migration is not continued. |
| `base64` | no | `true` sends each piece encoded, as `application/octet-stream`. Use it when a firewall in front of the destination blocks the pieces, as on WP Cloud. |

What you should see:

```json
{
  "success": true,
  "migration_id": "mig_20260925_101500",
  "status_url": "https://old-site.example.com/wp-json/wp-migrator/v1/remote-sync/status/mig_20260925_101500",
  "process_batch_url": "https://old-site.example.com/wp-json/wp-migrator/v1/remote-sync/process-batch",
  "status": "ready",
  "next_phase": "database",
  "table_prefix": "wp_",
  "message": "Migration prepared successfully. Use process_batch_url to start processing batches."
}
```

`table_prefix` is the source's table prefix.

## Send the site in batches

Call `process-batch` with the migration ID until the answer contains `"finalized": true`. Each call sends up to `batch_size` pieces (default `50`). The database goes first, then the files. The source decides which comes next.

```bash
curl -X POST "https://old-site.example.com/wp-json/wp-migrator/v1/remote-sync/process-batch" \
  -H "Content-Type: application/json" \
  -d '{"migration_id": "mig_20260925_101500", "batch_size": 50}'
```

While it runs:

```json
{
  "success": true,
  "migration_id": "mig_20260925_101500",
  "phase": "files",
  "processed_chunks": 50,
  "completed": false,
  "progress": {
    "percentage": 15.3,
    "current_item": "wp-content/uploads/2026/03/photo.jpg",
    "next_item": "wp-content/uploads/2026/03/photo-2.jpg",
    "sent_chunks": 500,
    "total_chunks": 3264
  },
  "skipped_files": [],
  "next_phase": "files"
}
```

`percentage` is for the current phase only. When the database phase ends, the answer says `"next_phase": "files"` and the message **Database phase completed. Continue with files phase.**

The last call finishes the migration on the destination and frees the source for the next migration:

```json
{
  "success": true,
  "migration_id": "mig_20260925_101500",
  "phase": "files",
  "processed_chunks": 14,
  "completed": true,
  "finalized": true,
  "progress": { "percentage": 100, "sent_chunks": 3264, "total_chunks": 3264 },
  "message": "Migration completed and finalized successfully",
  "summary": { "...": "..." }
}
```

`summary` is the destination's reply to the completion message. `skipped_files` lists files that disappeared from the source during the migration. A call after the end answers `"finalized": true` and **Migration is already completed**.

To resume after a failure, call `process-batch` again with the same `migration_id`. Pieces already sent are not sent again.

## Check progress

```bash
curl "https://old-site.example.com/wp-json/wp-migrator/v1/remote-sync/status/mig_20260925_101500"
```

The answer has `status.percentage` for the whole migration, the progress of `database` and `files`, `last_activity`, `skipped_files`, `status.phase` and a readable `status.message`. For a migration run over the API, `status.phase` stays `ready` while the batches run and becomes `completed` when everything is sent, so follow `status.percentage` or the `process-batch` answers instead.

## Send the .htaccess file

The migration never sends the `.htaccess` file from the source's main folder. To give the destination the source's rules, call this after the migration has finished:

```bash
curl -X POST "https://old-site.example.com/wp-json/wp-migrator/v1/transfer-htaccess" \
  -H "Content-Type: application/json" \
  -d '{"migration_id": "mig_20260925_101500"}'
```

**This overwrites the destination's `.htaccess`.** What you should see: `"transferred": true`. If the source has no `.htaccess`, the answer is `"transferred": false` with **No .htaccess file found on source**, and nothing changes on the destination.

## The bundled script

`migrate.php` in the plugin folder runs the start and the batches for you and prints the progress. It needs PHP with cURL on the computer you run it from, and sends both the database and the files, without exclusions or an IP address.

```bash
php migrate.php "https://new-site.example.com?token=<token>" "https://old-site.example.com" 50
```

The arguments are the destination address with its token, the source address (default `http://localhost`), and the batch size (default `50`). It ends with **Migration completed and finalized!** and the time it took, or with **✗ Error:** and the reason. The table and file counts it prints under **Summary** are always `0` in version 1.1.4; check the result on the destination instead.

## Troubleshooting

Errors come back as a WordPress REST error: `{"code": "...", "message": "...", "data": {"status": ...}}`.

**`sync_failed`: "Another migration is already in progress. Migration ID: … Please wait for it to complete or cancel it first."**
A migration from this source has not finished. Continue it with `process-batch` and that ID, or start a new one with `"force": true`. A migration left unfinished stops blocking new ones after two hours.

**`sync_failed`: "Token not found in destination URL" or "Invalid token format".**
`destination_url` is missing `?token=`, or the token is not the 64-character one from the destination.

**`batch_failed`: "Destination returned error: 403 - …Unauthorized - invalid token…"**
The destination's token changed after you started. Start a new migration with the current address.

**`batch_failed`: "Failed to send chunk: …"**
The source cannot reach the destination. Check the address, or pass `ip`. Then call `process-batch` again to continue.

**`batch_failed` with a 403 page or an HTML body from a firewall.**
A firewall in front of the destination blocked a piece. Start again with `"base64": true`.

## Every endpoint

[API reference](endpoints.md).
