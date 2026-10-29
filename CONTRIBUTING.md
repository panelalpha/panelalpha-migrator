# Contributing

Thanks for helping with PanelAlpha Migrator. This page covers how to set up the code, run the tests and send a change.

Questions go to [Discord](https://discord.gg/9twHWR7xGX) or the [forums](https://community.panelalpha.com/). Report a security issue privately through [PanelAlpha](https://manage.panelalpha.com/contact), not in a public issue.

## Set up

You need PHP 7.4 or newer and [Composer](https://getcomposer.org/).

```bash
git clone https://github.com/panelalpha/panelalpha-wordpress-migrator.git panelalpha-migrator
cd panelalpha-migrator
composer install
```

Name the folder `panelalpha-migrator`. The source sends to that exact path on the destination, so a test site needs the plugin under `wp-content/plugins/panelalpha-migrator/`.

## Run the tests

```bash
composer test
```

The unit tests do not start WordPress or MySQL. WordPress functions come from stubs in `tests/Support/`, and the database is SQLite in memory. Some tests start a PHP built-in server on `127.0.0.1` that stands in for the destination.

`composer.lock` holds PHPUnit 11, which needs PHP 8.2 or newer. On PHP 7.4 to 8.1, install an older PHPUnit first:

```bash
composer update phpunit/phpunit --with-all-dependencies
```

CI runs the tests on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5 for every push and pull request. Keep the code and the tests working on all of them:

- No PHP 8.0 syntax, such as union types, `mixed`, `match` or constructor property promotion. `symfony/polyfill-php80` covers functions such as `str_contains`.
- Give each PHPUnit attribute a matching annotation, because PHPUnit 9 reads only annotations. For example, `#[DataProvider('cases')]` also needs `@dataProvider cases` in the docblock.

## Check by hand

The unit tests do not replace a real migration. Before you send a change that touches sending, restoring or the API, run one between two WordPress sites:

- A full migration from the dashboard.
- A full migration over the API (`remote-sync` and `process-batch`, or `migrate.php`).
- The migrated site opens on the destination, and the source site's login works.
- An interrupted migration resumes, if you changed sending or progress.

Say in the pull request which WordPress, PHP and database versions you used.

## Build the zip

The release zip is built by `.github/workflows/release-package.yml` when a release is published. To build the same zip locally:

```bash
composer install --no-dev --optimize-autoloader
zip -r panelalpha-migrator.zip \
  panelalpha-migrator.php uninstall.php readme.txt composer.json composer.lock \
  assets src templates vendor
```

## Send a change

- Keep one pull request to one change.
- Write commits as [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`.
- Add a unit test in `tests/` for new behaviour.
- Update [`docs/`](docs/README.md) where a user would notice the change.
- Change the version in `panelalpha-migrator.php` (both the header and `PANELALPHA_MIGRATOR_VERSION`) only for a release. The release tag must match it.
- Update `composer.lock` when you change `composer.json`.

By sending a change you agree that it is licensed under GPL v2 or later, like the rest of the plugin. See [LICENSE](LICENSE).
