=== PanelAlpha Migrator ===
Contributors: panelalpha
Tags: migration, migrate, move site, transfer, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move a whole WordPress site, files and database, to another WordPress site. Install on both sites, paste one address, and start.

== Description ==

PanelAlpha Migrator copies a whole site, its files and its database, from the server it runs on now to another WordPress installation, usually a new site on a PanelAlpha server. PanelAlpha uses the same plugin to run migrations for you, without a browser.

How it works:

1. Install and activate the plugin on both sites: the source (the site you are moving) and the destination (the new WordPress).
2. On the destination, open Migrator in the dashboard menu, select the download icon (Destination) and copy the address.
3. On the source, open Migrator, paste the address and select Start Migration. Keep the tab open until it says the migration is complete.

Files and tables travel in small pieces, each checked on arrival, so a large site does not hit upload or time limits. A migration that stopped can continue from where it stopped.

What moves:

* Every table in the source database.
* Every file in the site folder, with permissions and dates, including hidden files.
* Your uploads, themes and plugins.
* The old site address is replaced with the new one in the database while it is sent, including inside saved plugin settings.

What stays on the destination:

* `wp-config.php`, so the destination still connects to its own database.
* The `.htaccess` file in the site folder.
* The migrator plugin and its own token.
* Tables that exist only on the destination.

`node_modules`, `.git` and `.svn` folders are always left out. You can leave out more under Advanced Options.

The migration replaces the destination site. Each table the source has is deleted on the destination and recreated from the source, and files with the same path are overwritten. Afterwards you log in to the destination with the source site's usernames and passwords. Back up the destination first if it holds anything you want to keep.

Treat the destination address like a password. While the plugin is active, anyone who has it can write to the destination. Deactivate and delete the plugin on both sites when you are happy with the result.

You can also migrate to a server before the domain points at it, by entering the new server's IP address under Destination IP Address (Optional).

== Installation ==

1. In WordPress, open Plugins > Add New Plugin, search for PanelAlpha Migrator, then select Install Now and Activate. Or upload the plugin zip under Plugins > Add New Plugin > Upload Plugin.
2. Do this on both sites, the source and the destination.
3. A Migrator item appears in the dashboard menu, for administrators only.
4. On the destination, open Migrator, select the download icon (Destination) and copy the address.
5. On the source, open Migrator, paste the address and select Start Migration.

You need an administrator login on both sites, PHP 7.4 or newer with the cURL extension, and WordPress must be able to write to `wp-content` on both sites.

== Frequently Asked Questions ==

= Will it overwrite what is already on the destination? =

Yes. Every table the source has is recreated on the destination, and files with the same path are overwritten. Tables and files that exist only on the destination are left alone. Back up the destination first if it holds anything you want to keep.

= Can I migrate before the domain points at the new server? =

Yes. Enter the new server's IP address in Destination IP Address (Optional) and paste the address with the final domain. The plugin connects to that IP address and the migrated site gets the final domain. If the new server has no valid HTTPS certificate for the domain yet, tick the box under the IP field that says not to verify the HTTPS certificate.

= My browser closed halfway. Do I start again? =

No. Open Migrator on the source in the same browser and select Resume Backup under Interrupted Migration Detected. What was already sent is not sent again.

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

PanelAlpha terms of service: https://www.panelalpha.com/terms-of-service
PanelAlpha privacy policy: https://www.panelalpha.com/privacy-policy

== Screenshots ==

1. On the destination: copy the address with its token.
2. On the source: paste the address and start the migration.

== Changelog ==

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

= 1.2.1 =
Both sites of a migration must run 1.2.1. Older versions do not work with it.
