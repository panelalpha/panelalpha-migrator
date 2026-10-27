# Migrate a site from the dashboard

A migration copies the source site's database and files into the destination and changes the source address to the destination address throughout the database. You start it from the **Migrator** page on the source. The browser tab drives the migration, so it runs only while that tab is open.

## Before you start

- The plugin is installed and active on both sites, and you opened **Destination** on the destination at least once, which creates its token: [Install the plugin](install.md).
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
   - **Destination IP Address (Optional)**: see [the new domain does not point at the destination yet](#the-new-domain-does-not-point-at-the-destination-yet). Leave empty when the domain already points at the destination. Under it is the checkbox **Do not verify the HTTPS certificate (only with a manual IP)**, which is described there too.
   - **Additional Exclusions (Optional)**: folders and file types to leave out: [Leave something out](../02-guides/what-gets-moved.md#leave-something-out).
   - **Backup Content**: **Database**, **Files**, or both. Both are selected by default, and at least one must stay selected. Only the part you select is sent; the other part stays as it is on the destination.

## 3. Start

Select **Start Migration** and keep the tab open.

What you should see, in order:

1. **Scan Progress**, while the plugin lists every file and table on the source.
2. **Backup Progress**, with the message **Sending migration info to destination...**, then the database, then the files. The bar shows the percentage for the part being sent.
3. **All backups completed! Finalizing...**
4. **Migration Completed Successfully!**, with **Files Migrated**, **Tables Migrated**, **Total Data** and **Duration**. This screen appears only after the destination has confirmed that it received everything.

If a file was deleted on the source while the migration ran, for example a cache or log file, the migration still finishes. A yellow notice then says **Some files were deleted on the source before they could be sent and were skipped.** and gives the number of skipped files. Those files are simply not on the destination.

Then open the destination and check it: [Check the result](after-the-migration.md).

A large site can take a long time. To pause, select **Stop Backup**. To continue after a closed tab or a lost connection: [Stop and resume](../02-guides/stop-and-resume.md).

## Troubleshooting

**"Token not found in URL. Please paste complete URL from destination." or "Invalid token format. Please paste the correct URL with token from destination."**
The pasted address is missing its `?token=` part or was cut off. Copy it again with **Copy** on the destination.

**"Failed to send migration info: …"**
Your browser could not reach the destination. Open the destination address in a new tab of the same browser. If it does not load, fix that first, then select **Start Migration** again.

**"Unauthorized - invalid token" while sending.**
The token was replaced on the destination after you copied the address. Copy the current address from the destination and start again.

**"The destination address is not allowed."**
The destination, or the IP address you entered, is on the same server or on a private network, which the source refuses by default. If that is what you want, list it in `allowed_destinations`: [Destinations on a private network](install.md#destinations-on-a-private-network).

**"Another migration is in progress. Wait for it to finish before scanning."**
A migration on this source is still running, or it was left open and has not yet timed out. A migration that sends nothing for 15 minutes stops blocking a new one. To end it sooner, open **Migrator** in the browser that started it and select **Stop Backup**: [Stop and resume](../02-guides/stop-and-resume.md).

**"Failed to send chunk: SSL certificate problem: …" or "Failed to send chunk: SSL: no alternative certificate subject name matches …"**
The destination's HTTPS certificate is checked, and the destination has no valid certificate for its domain yet. This happens most often when you send to an IP address before the domain points at the destination. Wait until the certificate is issued, or tick **Do not verify the HTTPS certificate (only with a manual IP)**: [the new domain does not point at the destination yet](#the-new-domain-does-not-point-at-the-destination-yet). The text after "Failed to send chunk:" comes from cURL on the source server and differs between versions.

**"Destination did not deliver the chunk: …"**
The destination received a piece but could not write it, for example "Directory not writable". Fix the cause on the destination, then resume: [Stop and resume](../02-guides/stop-and-resume.md). The destination writes the failure to a log: [Check the result](after-the-migration.md#files-the-migration-left-out).

**The migration stops with a timeout or a connection error.**
The source cannot reach the destination, or a single request took longer than two minutes. Check that the destination site opens from the internet, then resume: [Stop and resume](../02-guides/stop-and-resume.md).

### The new domain does not point at the destination yet

If the domain still points at the old server, the source would send the site there instead of to the destination. Enter the destination server's IP address in **Destination IP Address (Optional)**, for example `203.0.113.10`, and paste the address with the final domain as usual. The plugin connects to that IP address and still asks for the domain, so the destination site answers and the database gets the final domain. If the address has a port, such as `https://new-site.example.com:8443?token=<token>`, the plugin connects to that IP address on that port. The IP address is checked once when the migration starts, and every later request goes to it. Afterwards, point the domain at the destination server.

The first message of a dashboard migration is sent by your browser, which does not use the IP address. TODO: confirm with team that a dashboard migration works while the domain still points at the old server.

**The destination has no valid HTTPS certificate for the domain yet.** A certificate is usually issued only after the domain points at the server, so an HTTPS address sent to an IP address fails the certificate check. Tick **Do not verify the HTTPS certificate (only with a manual IP)**, which is under the IP field and can be ticked only when an IP address is entered. The migration then sends without checking the certificate, so use it only for a destination you control and only until the domain and its certificate are in place. It applies to this one migration. Without an IP address the checkbox does nothing.
