# SAC Database Inspector

SAC Database Inspector is a 100% free, GPL-licensed WordPress administration tool for
understanding database health, ownership, autoload impact, and stale data before deciding
whether maintenance is appropriate.

The plugin is inspection-first. It does not run background cleanup, provide a “clean
everything” action, track usage, or contact an external service on its own.

## Features

- Deterministic runtime, storage, and maintenance health reporting
- Explainable lower-is-better health penalty score
- Conservative plugin option, metadata, transient, and custom-table footprints
- Active, inactive, not-installed, core, theme, and unknown owner states
- Read-only ghost/orphan findings with confidence and evidence
- Paginated and searchable autoload inspection with protected-option controls
- Bounded cleanup batches with lightweight local safety snapshots
- Supported option/autoload/orphan-metadata restoration
- Redacted JSON, CSV, and HTML exports from one normalized report
- Optional provider-neutral WordPress AI Client explanations on WordPress 7.0+
- Graceful behavior when `information_schema` or AI is unavailable
- Existing `wpdi_read_only` audit-only mode

## Safety model

All state-changing requests require `manage_options`, a valid nonce, an explicit user
confirmation, an allowlisted target, and a successful safety snapshot where practical.
Cleanup is limited to 100 records per request. Protected WordPress options cannot have their
autoload state changed. Heuristic plugin ownership and ghost findings never authorize
deletion.

Snapshots are stored in a non-autoloaded WordPress option, retained for 14 days, capped at 20
operations and 1 MB per operation, and removed on uninstall. Options and orphan metadata can
be restored automatically. Post/comment cleanup snapshots are audit-only because reliably
recreating all related WordPress state would exceed the scope of a lightweight snapshot.

## Optional AI interpretation

On WordPress 7.0 or later, SAC can use the built-in WordPress AI Client when a compatible text
provider is configured under Settings → Connectors. SAC does not add API-key settings or a
provider-specific client.

AI requests happen only after an administrator clicks the explanation button. The request
contains a capped, centrally redacted, metadata-only report. It does not contain raw option
values or database credentials. AI can explain deterministic findings but cannot run SQL,
change options, clean data, restore snapshots, or override the health score.

Without WordPress AI support or a configured provider, every inspection, maintenance, and
export feature continues to work normally.

## Installation

1. Upload the plugin directory to `wp-content/plugins/`.
2. Activate **SAC Database Inspector**.
3. Open **Tools → DB Inspector**.

Requirements: WordPress 7.0 or later and PHP 7.4 or later.

## Read-only mode

```php
add_filter( 'wpdi_read_only', '__return_true' );
```

## Development

```bash
composer install
composer test
composer lint
```

The production build should honor `.distignore` so development dependencies, tests, and
configuration files are not shipped to WordPress.org.

See [the existing-code audit](docs/EXISTING-CODE-AUDIT.md),
[implementation notes](docs/IMPLEMENTATION-NOTES.md), and
[security review](docs/SECURITY-REVIEW.md).
