# Stop and resume

The source remembers which parts of the site it has already sent. A migration that was paused, or stopped because the tab closed or the connection dropped, continues from where it stopped instead of starting over.

## Pause

While the migration runs, select **Stop Backup** on the source and confirm. The part being sent finishes, and nothing more is sent until you resume.

## Resume

Resume from the same browser you started in. That browser remembers which migration was running.

1. On the source, open **Migrator**.
2. If the migration was still marked as running, it continues by itself and you see **Backup Progress** again.
3. Otherwise you see **Interrupted Migration Detected**, with the **Migration ID**, the **Last Activity** time, and how far the database and the files got. Select **Resume Backup**.

What you should see: **Resuming backup...**, then the progress bar from the percentage it had reached.

If the destination got a new token in the meantime, paste the current address from the destination into **Destination URL (paste from destination)** before you select **Resume Backup**.

## Start over instead

Under **Interrupted Migration Detected**, the **Migration Configuration** form is still shown. Fill it in and select **Start Migration** to scan the source again and send everything from the beginning. What already reached the destination is overwritten.

## Troubleshooting

**No "Interrupted Migration Detected" appears.**
You are in a different browser, or the browser's site data was cleared, so it does not know the migration. Start over with **Start Migration**.

**"Destination URL not found. Please paste the URL with token."**
Paste the address from the destination into **Destination URL (paste from destination)** and select **Resume Backup** again.

## From the API

A migration run over the API resumes the same way: call the next batch with the same `migration_id`. See [Migrate over the API](../03-api/migrate-over-the-api.md#send-the-site-in-batches).
