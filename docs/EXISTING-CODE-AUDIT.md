# Existing Free Plugin Audit

Audit date: 2026-08-09
Audited revision: `9d47690` on branch `1.1.0`
Runtime version before this work: `1.0.0`

## Scope and baseline

The repository contains only the existing free plugin. No paid repository or paid-version
code was consulted. The baseline has seven product files plus one tag-triggered deployment
workflow. It has no Composer or npm manifest, no test suite, no PHPCS configuration, and no
local WordPress test environment.

## Architecture and loading flow

1. `sac-database-inspector.php` defines `WPDI_VERSION`, directory/URL constants, and the
   singleton `WPDI_Database_Inspector` bootstrap.
2. The bootstrap loads `includes/class-wpdi-admin.php` on every request, but instantiates
   `WPDI_Admin` only in wp-admin.
3. `WPDI_Admin` registers the Tools page, assets, and both AJAX handlers in its constructor.
4. The Tools page synchronously runs all database statistics and renders the complete UI.
5. JavaScript provides the gauge, refresh AJAX, two browser confirmations, and cleanup AJAX.

The single 916-line admin class owns UI, SQL, scoring, source inference, AJAX authorization,
and all destructive cleanup. This is the primary architectural constraint.

## Existing UI and behavior

There is one page at Tools > DB Inspector and no tabs. It contains:

- a 0-100 gauge where a lower score is healthier;
- database size, autoload size/count, and external object-cache status;
- manual cleanup cards;
- the 20 largest options whose `autoload` value is exactly `yes`;
- a refresh button that updates headline values and cleanup counts.

The CSS is local and page-scoped. The JavaScript depends only on WordPress's bundled jQuery.
There is no frontend output, REST endpoint, cron task, telemetry, or external request.

## AJAX, public hooks, and stored state

Existing authenticated AJAX actions:

- `wpdi_get_stats` (read only)
- `wpdi_cleanup` (state changing)

Existing filters/actions that must remain compatible:

- `wpdi_health_score`
- `wpdi_read_only`
- `wpdi_cleanup_actions`
- `wpdi_before_cleanup`
- `wpdi_after_cleanup`

The baseline stores no plugin settings and creates no custom table. `uninstall.php` therefore
does nothing. Activation and deactivation callbacks are reserved no-ops.

## Existing database inspection

The page issues aggregate/count queries for:

- total database and options-table sizes via `information_schema.TABLES`;
- options where `autoload = 'yes'`;
- transient and expired transient counts;
- revisions, auto-drafts, trashed posts, spam comments, and trashed comments;
- orphaned postmeta and commentmeta using `LEFT JOIN`;
- the 20 largest autoloaded options.

`information_schema` errors are suppressed and a zero-size fallback is returned. The original
error-suppression state is not preserved. The other queries are unbounded aggregates and the
cleanup ID queries load every matching ID into PHP memory.

## Existing cleanup

The cleanup action supports expired transients, all transients, revisions, auto-drafts,
trashed posts, orphaned postmeta, orphaned commentmeta, spam comments, trashed comments, and
object-cache flushing. Post/comment/transient APIs are used in several paths; all-transient
and orphan-meta deletion use direct bulk SQL.

There are two browser confirmations, but no operation preview, server-side confirmation
token, snapshot, rollback, batch limit, or record-level audit result. The all-transient query
uses contains-style patterns and is broader than necessary. Hook filters can add an allowed
action name, but the switch cannot implement that action.

## Source identification

`get_option_source()` labels a small exact list as WordPress core and applies a hard-coded
prefix map for popular plugins. It returns `Likely: ...` for plugin matches and
`Custom / third-party` otherwise. It does not inspect installed plugin metadata, does not
distinguish active/inactive/missing owners, has no confidence model, and has a few broad
patterns such as `wp_` that can over-attribute custom data to core.

## Security baseline

Positive controls:

- the admin menu and page require `manage_options`;
- both AJAX handlers verify the `wpdi_nonce` nonce and `manage_options`;
- cleanup actions use a strict allowlist and respect `wpdi_read_only`;
- the one request parameter is unslashed and sanitized;
- rendered dynamic values are escaped;
- dynamic `information_schema` values and LIKE patterns are prepared;
- direct file access is blocked in PHP entry points.

Gaps and risks:

- destructive operations have no rollback snapshot and several are unbounded;
- there is no protected-option mechanism because options cannot yet be modified;
- source inference is too weak for deletion decisions (although it currently drives no deletion);
- raw errors are inconsistent between AJAX handlers;
- SQL failures can be reported as successful cleanups;
- no export/redaction layer exists;
- no checks cover usermeta or stale/missing-plugin artifacts;
- no automated coverage exists for CSRF, authorization, escaping, or SQL behavior.

No unsafe deserialization, file read/delete, REST authorization, or AI data path exists in the
baseline. The plugin never executes option values.

## Compatibility baseline

- Plugin header: WordPress 7.0 minimum, PHP 7.4 minimum, version 1.0.0.
- `readme.txt`: WordPress 6.9 minimum, tested through 7.0, stable tag 1.0.0 (inconsistent with
  the plugin header minimum).
- Multisite handling exists only for expired site-transient cleanup and two source labels.
- Autoload inspection assumes the legacy `yes` value and does not account for modern
  autoload values.
- Public hooks, AJAX names, menu slug, text domain, and the legacy flat stats array form the
  backward-compatibility surface.

## Tooling and release controls

The only workflow deploys to WordPress.org when a semantic-version Git tag is pushed, or via
manual dispatch. No release or publishing operation is needed for development. PHP is not
installed in the initial workspace, so local PHP syntax/PHPCS/Plugin Check require a container
or installation. Node and Docker executables are present.

## Functionality that must not regress

- Tools > DB Inspector access for administrators.
- Existing overview metrics and lower-is-better health score compatibility.
- All existing cleanup action identifiers and extension hooks.
- Read-only mode behavior.
- Graceful restricted-`information_schema` behavior.
- Multisite expired site-transient cleanup.
- Object-cache status and manual flush.
- Local-only admin assets, translation-ready strings, and no frontend impact.
