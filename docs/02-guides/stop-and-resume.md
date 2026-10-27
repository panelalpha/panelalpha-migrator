# Stop and resume

The source remembers which parts of the site it has already sent. A migration that was paused, or stopped because the tab closed or the connection dropped, continues from where it stopped instead of starting over.

## One migration at a time

A source runs one migration at a time. The migration takes its place when sending starts, not when the scan ends, and gives it up when it finishes, fails, or is stopped. While it holds that place, a second **Start Migration** is refused with **Another migration is in progress. Wait for it to finish before scanning.** A migration that was left open, for example because the tab was closed, gives up its place on its own after 15 minutes without a sent piece.

## Pause

While the migration runs, select **Stop Backup** on the source and confirm. The part being sent finishes, and nothing more is sent until you resume. Stopping frees the source, so you can start a different migration instead.

## Resume

Resume from the same browser you started in. That browser remembers which migration was running.

1. On the source, open **Migrator**.
2. If the migration was still marked as running, it continues by itself and you see **Backup Progress** again.
3. Otherwise you see **Interrupted Migration Detected**, with the **Migration ID**, the **Last Activity** time, and how far the database and the files got. Select **Resume Backup**.

What you should see: **Resuming backup...**, then the progress bar from the percentage it had reached. When the migration ends, **Migration Completed Successfully!** appears only after the destination has confirmed that it received everything.

If the destination got a new token in the meantime, paste the current address from the destination into **Destination URL (paste from destination)** before you select **Resume Backup**.

## Start over instead

Under **Interrupted Migration Detected**, the **Migration Configuration** form is still shown. Fill it in and select **Start Migration** to scan the source again and send everything from the beginning. What already reached the destination is overwritten.

## Troubleshooting

**No "Interrupted Migration Detected" appears.**
You are in a different browser, or the browser's site data was cleared, so it does not know the migration. Start over with **Start Migration**.

**"Destination URL not found. Please paste the URL with token." or "Token not found in URL. Please paste complete URL from destination."**
Paste the address from the destination into **Destination URL (paste from destination)** and select **Resume Backup** again. The browser keeps the address only until a migration finishes.

**"Failed to acquire migration lock. Another migration may be running." when you select Resume Backup.**
A different migration holds the source. Wait for it to finish, or stop it with **Stop Backup** in the browser that runs it. If you see "Another migration is in progress…" when you start a new migration, the previous one was left open less than 15 minutes ago: resume it, or wait.

## From the API

A migration run over the API resumes the same way: call the next batch with the same `migration_id`. See [Migrate over the API](../03-api/migrate-over-the-api.md#send-the-site-in-batches), which also covers a migration that was idle for more than 15 minutes.
