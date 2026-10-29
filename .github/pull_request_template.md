<!--
Thanks for contributing to PanelAlpha Migrator!
Please fill in the sections below. Keep the PR small and focused where you can.
-->

## What does this PR do?

<!-- A clear description of the change and why it's needed. -->

## Related issue

<!-- e.g. Closes #123. If there's no issue, briefly explain the motivation. -->

## Type of change

<!-- Tick all that apply. -->

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (existing behaviour changes)
- [ ] Documentation only
- [ ] Refactor / chore (no behaviour change)

## How was it tested?

<!--
Unit tests run on every push (`composer test`). They do not start WordPress or MySQL,
so also say how you verified the change by hand, and on which WordPress, PHP and database versions.
-->

- [ ] `composer test` passes, and new behaviour has a unit test in `tests/`
- [ ] A full migration from the dashboard, from a source site to a destination site
- [ ] A full migration over the API (`remote-sync` and `process-batch`, or `migrate.php`), if this touches the API
- [ ] The migrated site opens on the destination, and you can log in with the source site's login
- [ ] Resuming an interrupted migration, if this touches sending or progress

## Checklist

- [ ] My commits follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`)
- [ ] I updated [`docs/`](https://github.com/panelalpha/panelalpha-wordpress-migrator/blob/main/docs/README.md) where a user would notice the change
- [ ] I bumped the version in `panelalpha-migrator.php` if this is a release
- [ ] `composer.lock` is updated if I changed `composer.json`
- [ ] This PR is focused on a single change
- [ ] This does **not** contain a security fix that should be reported privately first

## Anything else?

<!-- Screenshots, trade-offs, follow-ups, or context for reviewers. -->
