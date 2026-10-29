# Check the result

When the source shows **Migration Completed Successfully!**, the destination already runs the copied site under its own address. Check it in a browser before you point visitors at it, then remove the plugin from both sites.

## Check the destination

1. Open the destination address in a private browser window.
2. Check that the page title, the menus and the content are the source site's, and that images load.
3. Open a few inner pages. If they show "Not Found" while the home page works, open **Settings** > **Permalinks** in the dashboard and select **Save Changes** once.
4. Log in to the destination dashboard with a username and password from the **source** site. The destination's old accounts were replaced.

## Files the migration left out

Some files are never copied: `wp-config.php`, the `.htaccess` file in the site's main folder, and the plugin itself. The destination keeps its own. If the source's `.htaccess` had rules you added yourself, such as redirects, copy those lines into the destination's `.htaccess` by hand. The full list: [What gets moved](../02-guides/what-gets-moved.md).

The destination keeps a log of files it did not write:

```text
wp-content/migrations/skipped_files_<migration-id>.log
```

Each line starts with the time and either `SKIPPED` or `FAILED`, then the file and the reason. `SKIPPED` marks a file the destination never overwrites, such as `wp-config.php`. `FAILED` marks a file the destination tried to write and could not, for example because a folder is not writable. A failed file is not skipped quietly: the migration stops with the message **Destination did not deliver the chunk**, and you fix the cause and resume it: [Stop and resume](../02-guides/stop-and-resume.md).

This is not the same as the warning **Some files were deleted on the source before they could be sent and were skipped.** That warning means a file was removed on the source while the migration ran. The migration finished, and those files are not on the destination.

## Remove the plugin

The destination accepts writes from anyone who has its address and token for as long as the plugin is active. When you are satisfied with the result:

1. On both sites, open **Plugins**, select **Deactivate** under **PanelAlpha Migrator**, then **Delete**.

Deleting the plugin from the dashboard also removes its token, its settings, its temporary tables and its files in `wp-content/migrations/`. That folder held the working files of the migration, and on the source a list of every file and table that was sent. The folder itself is removed only when it is empty, so delete it by hand if something else is left in it.

To migrate again later, install the plugin again and create a new token.

## Troubleshooting

**The destination still shows its old content.**
The page may come from a cache. Clear the cache of any caching plugin, of your host and of your browser. If it persists, the migration went to a different server: check the address you pasted on the source.

**Links or images point at the old address.**
The address is changed in plain text, in URL-encoded text, and inside saved settings. Some plugins save settings in a form the migrator cannot safely rebuild; those values are left unchanged so they cannot break. The part before the domain is kept as it was: a link to `http://` on the source becomes `http://` on the destination, and `https://` stays `https://`. Fix what remains in that plugin's settings, or with a search-and-replace plugin on the destination.

**You cannot log in to the destination.**
Use the source site's login. If you do not have it, reset the password with **Lost your password?** on the destination login page. The email goes to the address stored on the source.
