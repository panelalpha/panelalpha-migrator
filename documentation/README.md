# WordPress Migrator

WordPress Migrator copies a WordPress site, its files and its database, from one server to another WordPress installation. You run it from the WordPress dashboard on both sites, or PanelAlpha runs it for you over the plugin's API. This guide covers plugin version 1.1.4.

Two words are used on every page. The **source** is the site you are moving. The **destination** is the WordPress installation it moves to, usually a new site on a PanelAlpha server.

## Getting started

1. [Install the plugin](01-getting-started/install.md) on the source and the destination.
2. [Migrate a site from the dashboard](01-getting-started/migrate-a-site.md): copy the address from the destination, paste it into the source, start.
3. [Check the result](01-getting-started/after-the-migration.md) on the destination and remove the plugin.

## I want to…

| I want to… | Read |
|---|---|
| Know what is copied and what stays on the destination | [What gets moved](02-guides/what-gets-moved.md) |
| Leave out a folder or a file type | [What gets moved](02-guides/what-gets-moved.md#leave-something-out) |
| Migrate before the domain points at the new server | [Migrate a site](01-getting-started/migrate-a-site.md#the-new-domain-does-not-point-at-the-destination-yet) |
| Pause a migration, or continue one that stopped | [Stop and resume](02-guides/stop-and-resume.md) |
| Invalidate an address I shared | [Install the plugin](01-getting-started/install.md#create-a-new-token) |
| Run a migration without a browser | [Migrate over the API](03-api/migrate-over-the-api.md) |
| Look up an API endpoint | [API reference](03-api/endpoints.md) |

## For developers

How the plugin is put together, how to run its unit tests, and how a site travels from the source to the destination piece by piece: [Architecture](internal/architecture.md) and [Data transfer](internal/data-transfer.md).
