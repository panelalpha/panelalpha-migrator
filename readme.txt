=== PanelAlpha Migrator ===
Contributors: damianwilusz
Tags: migration, migrate, move site, transfer, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your WordPress site to a new host in a few clicks. Pages, images, themes, plugins, settings and user accounts all come along.

== Description ==

Moving a WordPress site by hand means downloading files, exporting the database, uploading it all again and fixing links to the old address. PanelAlpha Migrator does this for you from the WordPress dashboard. You install it on your old site and on the new one, copy one address, paste it, and click Start Migration.

Everything comes along: pages, posts, images, themes, plugins, settings and user accounts. Links that point to the old address are changed to the new one while the site is copied, even inside saved plugin settings.

The plugin was made for moving sites to servers that run PanelAlpha, and PanelAlpha uses the same plugin when it moves a site for you. You can use it to move a site to any other WordPress site as well.

= How to Move Your Site =

1. Install and activate PanelAlpha Migrator on both sites: your old site (the source) and the new WordPress you are moving to (the destination).
2. On the new site, open Migrator in the dashboard menu, click the download icon (Destination) and copy the address.
3. On the old site, open Migrator, paste the address and click Start Migration.
4. Keep the tab open until it says the migration is complete. Then log in to the new site with the username and password from your old site.

= Large Sites Are Fine =

Your site travels in small pieces, and each piece is checked when it arrives. Big sites do not hit upload size or time limits on your host. If the browser closes or the connection drops halfway, open Migrator again and continue from where it stopped. Nothing is sent twice.

= Move First, Switch the Domain Later =

You can copy the site to the new server while your domain still points at the old one. Enter the new server's IP address under Advanced Options (the gear icon). The new site is then ready before you change DNS.

= What Gets Copied =

* Every table in the old site's database, including users and settings.
* Every file in the site folder: uploads, themes, plugins and hidden files, with their permissions and dates.

= What Stays on the New Site =

* `wp-config.php`, so the new site stays connected to its own database.
* The `.htaccess` file in the site folder. Copy your own redirects over by hand.
* The migrator plugin and its own token.
* Tables that exist only on the new site.

`node_modules`, `.git` and `.svn` folders are never copied. You can leave out more files under Advanced Options.

= Before You Start =

The migration replaces the new site's content with the old site's. Each table that the old site has is deleted on the new site and created again from the old site, and files with the same path are overwritten. If the new site holds anything you want to keep, back it up first.

Treat the address you copy like a password. While the plugin is active, anyone who has it can write to the new site. When you are happy with the result, deactivate and delete the plugin on both sites.

== Installation ==

1. In WordPress, open Plugins > Add New Plugin, search for PanelAlpha Migrator, then click Install Now and Activate. You can also upload the plugin zip under Plugins > Add New Plugin > Upload Plugin.
2. Do this on both sites, the old one (source) and the new one (destination).
3. A Migrator item appears in the dashboard menu. Only administrators see it.
4. On the new site, open Migrator, click the download icon (Destination) and copy the address.
5. On the old site, open Migrator, paste the address and click Start Migration.

You need an administrator login on both sites, PHP 7.4 or newer with the cURL extension, and WordPress must be able to write to `wp-content` on both sites.

== Frequently Asked Questions ==

= Do I need FTP or database access? =

No. Everything happens in the WordPress dashboard. You only need an administrator login on both sites.

= Will it overwrite what is already on the destination? =

Yes. Every table the source has is recreated on the destination, and files with the same path are overwritten. Tables and files that exist only on the destination are left alone. Back up the destination first if it holds anything you want to keep.

= Can I migrate before the domain points at the new server? =

Yes. Enter the new server's IP address in Destination IP Address (Optional) and paste the address with the final domain. The plugin connects to that IP address and the migrated site gets the final domain. If the new server has no valid HTTPS certificate for the domain yet, tick the box under the IP field that says not to verify the HTTPS certificate.

= My browser closed halfway. Do I start again? =

No. Open Migrator on the source in the same browser and click Resume Backup under Interrupted Migration Detected. What was already sent is not sent again.

= Which login works on the destination afterwards? =

The source site's. The user accounts are part of the database, so they are copied with it.

= What about my redirects in .htaccess? =

The dashboard never copies the main `.htaccess`, so the destination keeps its own. Copy your own lines over by hand.

= Should I keep the plugin installed afterwards? =

No. While it is active, the destination accepts writes from anyone with its address and token. Deactivate and delete it on both sites once you are happy with the result. Deleting the plugin from the dashboard also removes its settings, its token, its temporary tables and its files in wp-content/migrations.

= Can the destination be on the same server or a private network? =

By default the source refuses a destination on its own server or on a private network. If you really want this, list the host name or IP address in `allowed_destinations` in the one-time setup file `wp-content/panelalpha-migrator-setup.php` on the source.

= Does a plugin update lose the token? =

No. The token and the settings are stored in the WordPress database, not in the plugin folder.

== External services ==

This plugin sends data to another WordPress site, and only while a migration runs.

* What is sent: the site's files and its full database, including user accounts and settings.
* Where it is sent: the destination WordPress site whose address you paste on the Migrator page, or that PanelAlpha sets for the migration. This is a site that you or your PanelAlpha host control, usually a server managed through PanelAlpha.
* When: only while a migration started by an administrator or by PanelAlpha is running. The plugin sends nothing when no migration is running.

The plugin does not send data to PanelAlpha servers otherwise. It makes no other outbound requests.

== Screenshots ==

1. On the new site: copy the address with its token.
2. On the old site: paste the address and start the migration.
3. Advanced Options: move to a server before the domain points at it, and leave out files you do not need.
4. The migration in progress. Files travel in small pieces, so large sites are fine.
5. The browser closed halfway. Click Resume Backup to continue from where it stopped.
6. Done. The summary shows how many files and tables were copied and how long it took.

== Changelog ==

= 1.2.2 =
* The migration lock is a plain JSON file in `wp-content/migrations/`. The plugin no longer writes PHP files to `wp-content`.
* The token of a migration is kept only in the WordPress database.
* The site address comes from WordPress, not from the request's Host header.
* Debug messages go to the PHP error log only when `WP_DEBUG` is on.
* Updated the Symfony polyfill packages.

= 1.2.1 =
* New name and slug: PanelAlpha Migrator, `panelalpha-migrator`. Requires WordPress 6.2.
* The destination receives the migration through the WordPress REST API, protected by its token. The separate receive script is removed.
* The destination keeps working during a migration: tables are imported into temporary tables and swapped in one step at the end, and PHP files move into place at the end. A failed swap is rolled back.
* The source checks that the destination answers and accepts the token before it sends anything.
* Abandoned migrations are cleaned up after 48 hours.
* The site token and the settings are now stored in the WordPress database. `config.php` is removed. A plugin update no longer loses the token.
* The destination keeps its own token and settings after a migration.
* New one-time setup file `wp-content/panelalpha-migrator-setup.php` to preset the token and settings. It also sets `allowed_destinations`.
* New `uninstall.php`: deleting the plugin removes its options, temporary tables, backups of old tables and its files in `wp-content/migrations/`.
* Requests to the destination use the WordPress HTTP API.
* Admin texts can be translated (text domain `panelalpha-migrator`).
* Removed unused code and packages.

= 1.2.0 =
* Every migration endpoint now asks for an administrator or the site's token.
* A migration reports success only after the destination has confirmed every piece.
* Destinations on the same server or on a private network are refused unless listed as allowed.
* The destination address is checked once and then pinned. The HTTPS certificate is checked.
* Files and tables are kept inside their folders. No database password is written to disk.
* Skipped files no longer stop a migration. A warning shows the count.
* One migration runs at a time. An abandoned one stops blocking after 15 minutes.
* PHP 7.4 to 8.5 supported.

= 1.1.4 =
* Migrate to a destination with a different table prefix.
* The destination refuses any file path that leaves the WordPress folder.

= 1.1.0 - 1.1.3 =
* Migrate to a server whose domain is not live yet, with a destination IP address.
* Support for WP Cloud and Bitnami layouts, and for binary data in the database.
* Older and unusual tables restore on strict MariaDB and MySQL servers.
* Tables restore in any order.

= 1.0.0 =
* First version: migrate a site from the dashboard or over the API, with stop and resume.

== Upgrade Notice ==

= 1.2.2 =
Works with 1.2.1 on the other site. A migration running during the update keeps its lock.

= 1.2.1 =
Both sites of a migration must run 1.2.1. Older versions do not work with it.
