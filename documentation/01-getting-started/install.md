# Install the plugin

The plugin goes on both sites: the source you are moving and the destination it moves to. It is the same plugin on both; which job it does depends on the mode you pick on the **Migrator** page. A fresh copy has no token yet; the destination creates one the first time you open **Destination**.

## Before you start

- An administrator login on both WordPress sites.
- PHP 8.0 or newer, with the cURL and PDO MySQL extensions, on both servers.
- WordPress must be able to write to `wp-content`. The plugin keeps its working files in `wp-content/migrations/` and creates that folder itself.
- On the destination, WordPress must also be able to write to `wp-content/plugins/wordpress-migrator/config.php`. That is where the token is saved.

## Install

1. Copy the `wordpress-migrator` folder into `wp-content/plugins/` on the site. If you have a zip file, you can upload it instead under **Plugins** > **Add New Plugin** > **Upload Plugin**. The folder must be named `wordpress-migrator`, because the source sends to that exact path on the destination, and must include its `vendor` folder. If you took the plugin from its Git repository rather than a zip, run `composer install --no-dev` in the folder first; that creates `vendor`.
2. Open **Plugins** in the WordPress dashboard and select **Activate** under **WordPress Migrator**.
3. Open **Migrator** in the dashboard menu.
4. Repeat on the other site.

What you should see: a **Migrator** item in the dashboard menu, only for administrators, with two icons at the top of the page. The upload icon is **Source**. The download icon is **Destination**.

## Get the destination address

The **token** is a 64-character code in the destination's address. The source must present it on every request, so only someone who has the address can write to the destination. A fresh install leaves the token empty; opening **Destination** writes one into `config.php` and shows the address.

1. On the destination, open **Migrator** and select the download icon (**Destination**).
2. Copy the address under **Your Destination URL with Token**.

What you should see: an address like `https://new-site.example.com?token=…` under **Your Destination URL with Token**. Use **Generate New Token** only when you want to invalidate a previous address; that asks for confirmation and replaces the one in the box.

## Settings in config.php

`config.php` in the plugin folder holds the plugin's settings. Most people never edit it.

| Setting | What it does |
|---|---|
| `token` | The token described above. Opening **Destination** the first time writes it here; **Generate New Token** replaces it. |
| `mode` | `source` or `destination`. Used only when `showButtons` is `false`. |
| `showButtons` | `true` (default) shows the Source and Destination icons. `false` hides them and fixes the page to `mode`. |
| `database` | Database name, user, password and host for the destination. Leave it commented out. Fill it in only when the plugin cannot read them from `wp-config.php`. |

With the icons shown, the mode you pick is remembered in your browser only. It does not change `config.php`.

A `branding.php` file next to `config.php`, if present, sets the author name and link shown for the plugin on the **Plugins** page. The multi-server PanelAlpha control panel adds it, with its own brand, to the plugin it hands out. Without the file, the plugin shows PanelAlpha.

## Troubleshooting

**The Migrator item does not appear after activation.**
The `vendor` folder is missing from `wp-content/plugins/wordpress-migrator/`. The plugin then loads nothing, without an error. Copy the complete plugin folder again.

**"WordPress Migrator: Cannot create migration directory. Please ensure … is writable."**
WordPress cannot write to `wp-content`. Ask your host to make it writable by the web server, then reload the page.

**"Failed to generate token: Failed to write config.php file. Check file permissions."**
WordPress cannot write to the plugin's `config.php`. Make that file writable by the web server and try again.

**"No token configured. Please generate one." in the address box, or "Failed to generate token".**
WordPress could not write a token to `config.php`. Make that file writable by the web server, reload **Destination**, or select **Generate New Token**.
