# Migrate over the API

The same migration can run without a browser. Another program, such as PanelAlpha, a script, or `migrate.php` from the repository, asks the source to prepare the migration, then asks it again and again to send the next batch until the whole site is on the destination. Nothing waits on an open tab, and each request is short.

This page is for whoever writes or runs that program. All requests go to the **source** site's WordPress REST API, under `/wp-json/panelalpha-migrator/v1/`. The examples use `https://old-site.example.com` for the source and `https://new-site.example.com` for the destination.

## Before you start

- The plugin is active on both sites, and you have the destination's address with its token: [Install the plugin](../01-getting-started/install.md#get-the-destination-address) and [Copy the address from the destination](../01-getting-started/migrate-a-site.md#1-copy-the-address-from-the-destination).
- **The migration replaces the destination site.** See [What gets moved](../02-guides/what-gets-moved.md).
- One migration at a time per source. The migration takes its place when `remote-sync` has prepared it, and gives it up when the last batch has finalized, or when the migration is stopped or fails. A second start is refused while one holds it, unless you pass `force`. A migration that has sent no piece for 15 minutes no longer holds it.
- Every request to the source carries the **source's** token, from its **Migrator** page, in the `X-Migrator-Token` header (or as `?token=`). It is not the destination's token inside `destination_url`. A request without it gets `401`, unless it comes from a logged-in administrator. A fresh copy of the plugin has no token: open **Migrator** on the source and select the download icon (**Destination**) once, which creates it.

## Start the migration

The source lists every file and table, saves that list, and answers with a migration ID.

```bash
curl -X POST "https://old-site.example.com/wp-json/panelalpha-migrator/v1/remote-sync" \
  -H "Content-Type: application/json" \
  -H "X-Migrator-Token: <source-token>" \
  -d '{
    "destination_url": "https://new-site.example.com?token=<token>",
    "backup_database": true,
    "backup_files": true
  }'
```

| Field | Required | What it does |
|---|---|---|
| `destination_url` | yes | The destination's address with `?token=`, as shown on its **Migrator** page. The token must be 64 hexadecimal characters. |
| `backup_database` | no | Send the database. Default `true`. Only the selected part is sent. Use JSON `true` or `false`; the strings `"false"` and `"0"` and the number `0` also mean off. |
| `backup_files` | no | Send the files. Default `true`. Read the same way as `backup_database`. At least one of the two must be on. |
| `exclusions` | no | Extra items to leave out, separated by commas or new lines, such as `"*.log,node_modules"`. The rules are on [What gets moved](../02-guides/what-gets-moved.md#leave-something-out). |
| `ip` | no | The destination server's IP address, for a domain that does not point at it yet. Exactly one IPv4 or IPv6 address; a list, a number or a host name is refused. Requests go to this IP address and still name the domain, and the database gets the domain. If `destination_url` has a port, requests use that port. Only this IP address is checked, see below. |
| `insecure_tls` | no | `true` skips the HTTPS certificate check for this migration. It only works together with `ip`, and is ignored without it. Default `false`. Use it only when the destination has no valid certificate for the domain yet. |
| `force` | no | `true` removes the lock left by an earlier migration on this source, so this one can start. The earlier migration is not continued. |
| `base64` | no | `true` sends each piece encoded, as `application/octet-stream`. Use it when a firewall in front of the destination blocks the pieces, as on WP Cloud. |

What you should see:

```json
{
  "success": true,
  "migration_id": "mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5",
  "status_url": "https://old-site.example.com/wp-json/panelalpha-migrator/v1/remote-sync/status/mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5",
  "process_batch_url": "https://old-site.example.com/wp-json/panelalpha-migrator/v1/remote-sync/process-batch",
  "status": "ready",
  "next_phase": "database",
  "table_prefix": "wp_",
  "message": "Migration prepared successfully. Use process_batch_url to start processing batches."
}
```

`table_prefix` is the source's table prefix.

### Destinations that are refused

A destination on the same server or on a private network is refused with `400` and the code `destination_not_allowed`: "The destination address is not allowed." The check covers loopback, private, link-local and similar addresses. To allow one, list its host name or IP address in `allowed_destinations` in the setup file on the source: [Destinations on a private network](../01-getting-started/install.md#destinations-on-a-private-network). Without `ip`, the destination's domain is looked up once and the first address is used for the whole migration, unless the host name is listed in `allowed_destinations`, which is trusted as it is. With `ip`, only that IP address is checked and the domain is not looked up.

### HTTPS certificates

The source checks the destination's HTTPS certificate. If the destination has no valid certificate for its domain yet, pass `ip` together with `"insecure_tls": true` to skip the check for this migration. Pass it only for a destination you control.

## Send the site in batches

Call `process-batch` with the migration ID until the answer contains `"finalized": true`. Each call sends up to `batch_size` pieces (default `50`). The database goes first, then the files. The source decides which comes next.

```bash
curl -X POST "https://old-site.example.com/wp-json/panelalpha-migrator/v1/remote-sync/process-batch" \
  -H "Content-Type: application/json" \
  -H "X-Migrator-Token: <source-token>" \
  -d '{"migration_id": "mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5", "batch_size": 50}'
```

While it runs:

```json
{
  "success": true,
  "migration_id": "mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5",
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
  "migration_id": "mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5",
  "phase": "files",
  "processed_chunks": 14,
  "completed": true,
  "finalized": true,
  "progress": { "percentage": 100, "sent_chunks": 3264, "total_chunks": 3264 },
  "message": "Migration completed and finalized successfully",
  "summary": { "...": "..." }
}
```

`summary` is the destination's reply to the completion message. `skipped_files` lists files that disappeared from the source during the migration. When it is not empty, the last answer also has a `warning` ("Some files were deleted on the source before they could be sent and were skipped."), and `success` stays `true`. This is not the destination's own log of files it could not write: [Check the result](../01-getting-started/after-the-migration.md#files-the-migration-left-out). A call after the end answers `"finalized": true` and **Migration is already completed**.

To resume after a failure, call `process-batch` again with the same `migration_id`. Pieces already sent are not sent again. This also works after the migration was idle for more than 15 minutes: `process-batch` takes the place again, unless another migration has taken it in the meantime.

## Check progress

```bash
curl "https://old-site.example.com/wp-json/panelalpha-migrator/v1/remote-sync/status/mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5" \
  -H "X-Migrator-Token: <source-token>"
```

The answer has `status.percentage` for the whole migration, the progress of `database` and `files`, `last_activity`, `skipped_files`, `status.phase` and a readable `status.message`. For a migration run over the API, `status.phase` stays `ready` while the batches run and becomes `completed` when everything is sent, so follow `status.percentage` or the `process-batch` answers instead.

## Send the .htaccess file

The migration never sends the `.htaccess` file from the source's main folder. To give the destination the source's rules, call this after the migration has finished:

```bash
curl -X POST "https://old-site.example.com/wp-json/panelalpha-migrator/v1/transfer-htaccess" \
  -H "Content-Type: application/json" \
  -H "X-Migrator-Token: <source-token>" \
  -d '{"migration_id": "mig_20260925_101500_3f9c2a7e5b1d48c6a0e2f4b6d8c1a3e5"}'
```

**This overwrites the destination's `.htaccess`.** What you should see: `"transferred": true`. If the source has no `.htaccess`, the answer is `"transferred": false` with **No .htaccess file found on source**, and nothing changes on the destination.

## The script in the repository

`migrate.php` runs the start and the batches for you and prints the progress. It is in the plugin's repository, not in the release zip, and runs only from the command line. Run it from any machine that can reach both sites, and give it the source's token as the second argument. It needs PHP with cURL, and sends both the database and the files, without exclusions or an IP address.

```bash
php migrate.php "https://new-site.example.com?token=<destination_token>" <source_token> "https://old-site.example.com" 50
```

The arguments are the destination address with its token, the source's token, the source address (default `http://localhost`), and the batch size (default `50`). It ends with **Migration completed and finalized!** and the time it took, or with **✗ Error:** and the reason. The table and file counts it prints under **Summary** are always `0` in version 1.2.0; check the result on the destination instead.

## Troubleshooting

Errors come back as a WordPress REST error: `{"code": "...", "message": "...", "data": {"status": ...}}`.

**`sync_failed`: "Another migration is already in progress. Migration ID: … Please wait for it to complete or cancel it first."**
A migration from this source has not finished. Continue it with `process-batch` and that ID, or start a new one with `"force": true`. A migration left unfinished stops blocking new ones after 15 minutes without a sent piece.

**`sync_failed`: "Token not found in destination URL" or "Invalid token format".**
`destination_url` is missing `?token=`, or the token is not the 64-character one from the destination.

**`batch_failed`: "Destination returned error: 403 - …Unauthorized - invalid token…"**
The destination's token changed after you started. Start a new migration with the current address.

**`batch_failed`: "Failed to send chunk: …"**
The source cannot reach the destination. Check the address, or pass `ip`. Then call `process-batch` again to continue. When the text after the colon starts with "SSL certificate problem" or "SSL: no alternative certificate subject name matches", the destination has no valid certificate for its domain: see [HTTPS certificates](#https-certificates). That text comes from cURL and differs between versions.

**`batch_failed`: "Destination did not deliver the chunk: …"**
The destination received the piece but could not write it, for example "Directory not writable". The piece stays pending. Fix the cause on the destination and call `process-batch` again.

**`batch_failed`: "Destination did not confirm finalization" or "Failed to send completion signal: …".**
Every piece was sent, but the destination did not confirm the end. The migration keeps its place. Call `process-batch` again with the same `migration_id` to repeat the last step.

**`batch_failed`: "Another migration is in progress. Migration ID: …".**
This migration was idle for more than 15 minutes and another migration has started on the source since. Wait for that one to finish, then call `process-batch` again.

**`destination_not_allowed`.**
See [Destinations that are refused](#destinations-that-are-refused).

**`batch_failed` with a 403 page or an HTML body from a firewall.**
A firewall in front of the destination blocked a piece. Start again with `"base64": true`.

## Every endpoint

[API reference](endpoints.md).
