# Migrate a site from the dashboard

A migration copies the source site's database and files into the destination and changes the source address to the destination address throughout the database. You start it from the **Migrator** page on the source. The browser tab drives the migration, so it runs only while that tab is open.

## Before you start

- The plugin is installed and active on both sites, and you created a new token on the destination: [Install the plugin](install.md).
- **The migration replaces the destination site.** Each table the source has is deleted on the destination and recreated from the source. Files with the same path are overwritten. If the destination holds anything you want to keep, back it up first. There is no undo.
- After the migration, you log in to the destination with the source site's usernames and passwords.
- Your browser sends the first message to the destination itself, so the destination address must open in the browser you use, as well as from the source server.

## 1. Copy the address from the destination

1. On the destination, open **Migrator** and select the download icon (**Destination**).
2. Select **Copy** next to **Your Destination URL with Token**.

The address is the destination's site address followed by its token:

```text
https://new-site.example.com?token=<token>
```

Anyone who has this address can write to the destination. Do not post it anywhere public. If it leaks, select **Generate New Token** on the destination.

## 2. Choose what to send

1. On the source, open **Migrator** and keep the upload icon (**Source**) selected.
2. Paste the address into **Destination URL (paste from destination)**.
3. Optional: select the gear icon (**Advanced Options**) for three more settings.
   - **Destination IP Address (Optional)**: see [the troubleshooting entry](#the-new-domain-does-not-point-at-the-destination-yet). Leave empty when the domain already points at the destination.
   - **Additional Exclusions (Optional)**: folders and file types to leave out: [Leave something out](../02-guides/what-gets-moved.md#leave-something-out).
   - **Backup Content**: **Database**, **Files**, or both. Both are selected by default, and at least one must stay selected.

## 3. Start

Select **Start Migration** and keep the tab open.

What you should see, in order:

1. **Scan Progress**, while the plugin lists every file and table on the source.
2. **Backup Progress**, with the message **Sending migration info to destination...**, then the database, then the files. The bar shows the percentage for the part being sent.
3. **All backups completed! Finalizing...**
4. **Migration Completed Successfully!**, with **Files Migrated**, **Tables Migrated**, **Total Data** and **Duration**.

Then open the destination and check it: [Check the result](after-the-migration.md).

A large site can take a long time. To pause, select **Stop Backup**. To continue after a closed tab or a lost connection: [Stop and resume](../02-guides/stop-and-resume.md).

## Troubleshooting

**"Token not found in URL. Please paste complete URL from destination." or "Invalid token format. Please paste the correct URL with token from destination."**
The pasted address is missing its `?token=` part or was cut off. Copy it again with **Copy** on the destination.

**"Failed to send migration info: …"**
Your browser could not reach the destination. Open the destination address in a new tab of the same browser. If it does not load, fix that first, then select **Start Migration** again.

**"Unauthorized - invalid token" while sending.**
The token was replaced on the destination after you copied the address. Copy the current address from the destination and start again.

**The migration stops with a timeout or a connection error.**
The source cannot reach the destination, or a single request took longer than two minutes. Check that the destination site opens from the internet, then resume: [Stop and resume](../02-guides/stop-and-resume.md).

### The new domain does not point at the destination yet

If the domain still points at the old server, the source would send the site there instead of to the destination. Enter the destination server's IP address in **Destination IP Address (Optional)**, for example `203.0.113.10`, and paste the address with the final domain as usual. The plugin connects to that IP address and still asks for the domain, so the destination site answers and the database gets the final domain. Afterwards, point the domain at the destination server.
