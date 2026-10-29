# What gets moved

A migration copies every table in the source site's database and every file in the site's main folder, the one that holds `wp-config.php` and `wp-content`. A few files are always left out so the destination keeps working with its own database and server settings. You can leave out more.

## The database

Every table in the source database is copied, not only WordPress's own. On the destination, each of those tables is deleted and created again with the source's content. Tables that exist only on the destination are not touched.

While the data is read, the source address is changed to the destination address, so the destination opens under its own domain from the first page. If the two sites use a different table prefix (the letters before each table name, `wp_` by default), the tables are renamed to the destination's prefix.

## The files

Every file is copied with its folder, its permissions and its modification date, including hidden files whose names start with a dot. Large files are sent in pieces, so there is no size limit per file.

These are always left out:

| Left out | Why |
|---|---|
| `wp-config.php` | The destination keeps its own, with its own database login. |
| `.htaccess` in the main folder | The destination keeps its own server rules. |
| `wp-content/plugins/panelalpha-migrator/` | The plugin, with its token, stays as it is on each site. |
| `wp-content/migrations/` | The plugin's working files. |
| `node_modules`, `.git` and `.svn` folders, wherever they are | Development files a live site does not need. |

A file that disappears from the source while the migration runs, such as a cache or log file, is skipped, and the migration still finishes. The dashboard then shows a warning with the number of skipped files, and the API answer lists them: [Check the result](../01-getting-started/after-the-migration.md#files-the-migration-left-out).

## Leave something out

On the source, select the gear icon (**Advanced Options**) and write what to leave out in **Additional Exclusions (Optional)**, **one item per line**:

```text
*.log
wp-content/cache
backup-old
```

- `*.log` is a file type: every file ending in `.log`, in any folder.
- `wp-content/cache` is a path from the main folder: that folder, or that file if it is one, such as `wp-content/debug.txt`.
- `backup-old`, a name without a dot, is every folder with that name, wherever it appears.
- `error.txt`, a name with a dot, is that file in the main folder only.

The hint under the box shows the examples separated by commas. In version 1.2.0 only one item per line is read correctly on this page.

To copy only the database, or only the files, clear the other box under **Backup Content**. Only the part you keep selected is sent, and the other part stays as it is on the destination.

## From the API

The same choices are available when PanelAlpha or a script starts the migration: `backup_database`, `backup_files` and `exclusions` (commas or new lines both work there) in [Migrate over the API](../03-api/migrate-over-the-api.md#start-the-migration). A migration started over the API also leaves out any folder named `panelalpha-migrator`. Other `.htaccess` files, such as the ones that protect WooCommerce uploads, are copied. The main folder's `.htaccess` can be sent separately afterwards: [Send the .htaccess file](../03-api/migrate-over-the-api.md#send-the-htaccess-file).
