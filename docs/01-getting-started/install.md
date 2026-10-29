# Install the plugin

The plugin goes on both sites: the source you are moving and the destination it moves to. It is the same plugin on both; which job it does depends on the mode you pick on the **Migrator** page. A fresh copy has no token yet; the destination creates one the first time you open **Destination**.

## Before you start

- An administrator login on both WordPress sites.
- PHP 7.4 or newer, with the cURL extension, on both servers.
- WordPress must be able to write to `wp-content`. The plugin keeps its working files in `wp-content/migrations/` and creates that folder itself.

## Install

1. Copy the `panelalpha-migrator` folder into `wp-content/plugins/` on the site. If you have a zip file, you can upload it instead under **Plugins** > **Add New Plugin** > **Upload Plugin**. The folder must be named `panelalpha-migrator`, because the source sends to that exact path on the destination, and must include its `vendor` folder. If you took the plugin from its Git repository rather than a zip, run `composer install --no-dev` in the folder first; that creates `vendor`.
2. Open **Plugins** in the WordPress dashboard and select **Activate** under **PanelAlpha Migrator**.
3. Open **Migrator** in the dashboard menu.
4. Repeat on the other site.

What you should see: a **Migrator** item in the dashboard menu, only for administrators, with two icons at the top of the page. The upload icon is **Source**. The download icon is **Destination**.

## Get the destination address

The **token** is a 64-character code in the destination's address. The source must present it on every request, so only someone who has the address can write to the destination. A fresh install has no token yet; opening **Destination** creates one, saves it in the WordPress database and shows the address. A plugin update does not lose it. The token also protects the source's own API, see [Migrate over the API](../03-api/migrate-over-the-api.md#before-you-start).

1. On the destination, open **Migrator** and select the download icon (**Destination**).
2. Copy the address under **Your Destination URL with Token**.

What you should see: an address like `https://new-site.example.com?token=…` under **Your Destination URL with Token**. Use **Generate New Token** only when you want to invalidate a previous address; that asks for confirmation and replaces the one in the box.

## Settings

The plugin keeps its token and settings in the WordPress database, in the options `panelalpha_migrator_token` and `panelalpha_migrator_settings`. Most people never change them. After a migration the destination keeps its own token and settings.

| Setting | What it does |
|---|---|
| `token` | The token described above. Opening **Destination** the first time creates it; **Generate New Token** replaces it. |
| `mode` | `source` or `destination`. Used only when `showButtons` is `false`. |
| `showButtons` | `true` (default) shows the Source and Destination icons. `false` hides them and fixes the page to `mode`. |
| `allowed_destinations` | A list of host names or IP addresses that may be used as a destination even though they are on this server or a private network. Empty by default. See below. |

To preset them, for example from a script or a control panel, put a file named `wp-content/panelalpha-migrator-setup.php` on the site. It returns an array. Keys you leave out are not changed.

```php
<?php
return [
    'token' => '<64 hex characters>',
    'mode' => 'destination',
    'showButtons' => true,
    'allowed_destinations' => [],
];
```

On the next page load the plugin deletes the file and, only after the delete worked, saves the values. A token that is not 64 hexadecimal characters is ignored. If the file cannot be deleted, nothing is saved.

### Destinations on a private network

The source refuses a destination that points at the server itself or at an internal network: `localhost`, and the addresses `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16` and `100.64.0.0/10`, documentation, multicast and other non-routable ranges, plus the IPv6 equivalents. Only `http` and `https` addresses are accepted. The message is **The destination address is not allowed.** If the destination really is on such a network, for example a staging server in the same data centre, add its host name or IP address to `allowed_destinations` in the setup file **on the source**:

```php
<?php
return [
    'allowed_destinations' => ['staging.example.com', '10.0.0.5'],
];
```

When you enter a manual IP address, only that IP address is checked against the list, and the domain is not looked up. Without a manual IP, the plugin looks up the domain once when the migration starts, checks every address it gets, and then sends everything to the first one, even if the DNS record changes during the migration. A host name that is itself on the list is trusted without a lookup.

With the icons shown, the mode you pick is remembered in your browser only. It does not change the saved settings.

A `branding.php` file in the plugin folder, if present, sets the author name and link shown for the plugin on the **Plugins** page. The multi-server PanelAlpha control panel adds it, with its own brand, to the plugin it hands out. Without the file, the plugin shows PanelAlpha.

## Troubleshooting

**The Migrator item does not appear after activation.**
The `vendor` folder is missing from `wp-content/plugins/panelalpha-migrator/`. The plugin then loads nothing, without an error. Copy the complete plugin folder again.

**"PanelAlpha Migrator: Cannot create migration directory. Please ensure … is writable."**
WordPress cannot write to `wp-content`. Ask your host to make it writable by the web server, then reload the page.

**"Failed to generate token: …" on the Destination page, and the address box stays empty.**
WordPress could not save the token in its database. Reload **Destination**, or select **Generate New Token**. If it keeps failing, ask your host whether the database accepts writes.
