=== SAC Database Inspector ===
Contributors: jackofall1232
Tags: database, autoload, database cleaner, performance, diagnostics
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect WordPress database health, plugin footprints, autoloaded options, and questionable leftovers with carefully controlled maintenance tools.

== Description ==

SAC Database Inspector helps administrators understand what is using the WordPress database before deciding whether maintenance is appropriate.

It is inspection-first: no background cleanup, “clean everything” action, telemetry, paid feature, or automatic external request is included.

= Database diagnostics =

* Runtime, storage, and maintenance health categories
* Deterministic and explainable health penalty score
* Database and table sizes when the host permits access
* Graceful fallback when `information_schema` is restricted
* Object-cache status and bounded maintenance counts

= Ownership and footprints =

* Conservative plugin option, metadata, transient, and custom-table estimates
* Active, inactive, not-installed, WordPress core, theme, and unknown owner states
* Explicit ownership confidence and evidence
* Inactive installed plugins are never treated as missing

= Autoload and ghost data =

* Paginated, searchable, size-sorted autoload inspector
* Serialized, protected, owner, status, confidence, and size indicators
* Capped option previews with centralized sensitive-name redaction
* Orphan metadata, expired transient, missing-plugin option/table, and stale cron findings
* Heuristic findings are read-only and never automatically deleted

= Controlled maintenance and rollback =

* Capability, nonce, target, read-only-mode, and explicit-confirmation checks
* At most 100 records changed per request
* Lightweight local safety snapshots before supported changes
* Restoration for options, autoload state, and orphan metadata
* Protected WordPress options cannot have autoload changed

= Reports and optional AI =

* Redacted JSON, CSV, and HTML exports from one normalized report
* Optional WordPress 7.0 AI Client explanations when a text provider is configured
* Provider-neutral integration with no SAC API-key settings
* AI receives capped metadata, not raw option values or database credentials
* AI cannot run SQL, clean data, change options, or restore snapshots
* All deterministic features work without AI

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through the Plugins screen.
2. Activate SAC Database Inspector.
3. Open Tools → DB Inspector.

== Frequently Asked Questions ==

= Does the plugin change data automatically? =

No. Scanning is read-only. Every maintenance action is initiated explicitly by an administrator, handles a bounded batch, and creates a safety snapshot where practical.

= Is plugin ownership guaranteed? =

No. SAC uses conservative exact-prefix evidence and reports confidence. Unknown data remains unknown, and heuristic ownership never authorizes deletion.

= Is data from an inactive plugin considered orphaned? =

No. Inactive but installed plugins have a distinct status and are not treated as missing.

= What happens when database size access is restricted? =

Table and total-size fields are marked unavailable. Other diagnostics continue to work, and restricted `information_schema` access is not treated as database failure.

= How are snapshots stored? =

Snapshots are stored locally in a non-autoloaded WordPress option, limited to 20 operations and 14 days, and removed when the plugin is uninstalled. Snapshot values are excluded from exports and AI context.

= Does AI have access to cleanup tools? =

No. AI is an optional explanation layer. It receives a capped, redacted metadata report only after an administrator requests an explanation. No mutation ability or database credential is exposed.

= Does SAC contact an AI provider when AI is unavailable? =

No. AI UI is enabled only when WordPress reports that configured text generation is supported. All other functionality is independent of AI.

= Can I disable every maintenance action? =

Yes. Add `add_filter( 'wpdi_read_only', '__return_true' );` in site-specific code.

== Screenshots ==

1. Database health overview and bounded maintenance actions
2. Runtime, storage, and maintenance health categories
3. Conservative plugin database footprints
4. Paginated autoload inspector
5. Read-only ghost data findings
6. Redacted reports and optional AI interpretation
7. Safety snapshot history and restoration

== Privacy and external services ==

SAC has no telemetry or tracking. Database inspection and exports are local.

If an administrator explicitly requests an AI explanation, SAC passes a capped and redacted metadata-only diagnostic context to the provider selected through WordPress Settings → Connectors. The external service, data handling, and terms depend on the connector/provider configured by the site owner. SAC does not store provider credentials and does not send raw option values, database credentials, WordPress salts, private keys, or authentication tokens.

== Changelog ==

= 1.1.0 =

* Added modular report, ownership, cleanup, snapshot, export, and AI services.
* Added normalized deterministic diagnostic reports and explainable health categories.
* Added conservative plugin database footprint estimates and owner status distinctions.
* Added read-only ghost data findings with confidence and reasons.
* Added paginated/searchable autoload inspection, protected options, and redacted previews.
* Added bounded cleanup, local safety snapshots, and supported restoration.
* Added redacted JSON, CSV, and HTML exports.
* Added optional provider-neutral WordPress 7.0 AI Client explanations.
* Preserved existing cleanup identifiers, hooks, AJAX names, read-only mode, and flat statistics response.
* Added PHPUnit, WordPress Coding Standards, PHP compatibility, and production-build configuration.
* Removed no functionality and added no telemetry, paid features, provider SDKs, or automatic external calls.

= 1.0.0 =

* Stable release with the original inspection dashboard and manual cleanup actions.

= 0.2.0 =

* Added contextual source identification to the autoload table.

= 0.1.1 =

* Naming and compliance updates.

= 0.1.0 =

* Initial database inspection dashboard, manual cleanup, read-only mode, and multisite support.

== Upgrade Notice ==

= 1.1.0 =

Adds modular diagnostics, ownership-aware footprints, bounded safety snapshots, redacted reports, and optional WordPress AI explanations. No release tag is created by this source change.
