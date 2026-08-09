# Implementation Plan and Notes

## Plan approved by the specification

The implementation will be incremental and keep `WPDI_Admin` as a compatibility-facing
controller while moving scanning and mutation logic into focused classes.

1. **Foundation:** add services for ownership, redaction, reporting, cleanup, and snapshots;
   preserve the existing bootstrap, menu slug, hooks, AJAX names, and legacy stats shape.
2. **Central report and health:** build one deterministic normalized report with runtime,
   storage, and maintenance categories; retain the existing score filter and make every
   penalty explainable.
3. **Ownership and footprints:** inventory installed plugins and conservatively aggregate
   exact-prefix artifacts, status, size, tables, and confidence. Uncertain matches remain
   unknown and never become deletion authority.
4. **Ghost data and autoload:** add bounded orphan counts/findings and a paginated/searchable
   autoload inspector with protected-option and sensitive-value controls.
5. **Snapshots and mutations:** batch existing cleanup, snapshot reversible rows where
   practical, fail closed when a required snapshot cannot be written, and add guarded
   autoload changes/restoration. Heuristic findings stay read only.
6. **Exports:** generate JSON/CSV/HTML from the central report through one redaction layer.
7. **WordPress AI Client:** feature-detect WordPress 7.0's `wp_ai_client_prompt()`, verify text
   support, and send only the redacted normalized report when an administrator explicitly
   requests an explanation. No provider client, credentials UI, tool/ability registration,
   or mutation capability will be added.
8. **UI:** use minimally disruptive tabs and progressive disclosure while keeping existing
   dashboard concepts recognizable.
9. **Hardening and validation:** review authorization, nonces, SQL, escaping, failure modes,
   performance limits, multisite behavior, docs, and release metadata; run all available
   syntax, standards, Plugin Check, and targeted tests.

## Compatibility decisions

- Version metadata will be aligned at `1.1.0` as explicitly requested; no Git tag, release,
  WordPress.org publish, push, or workflow dispatch will be performed.
- The existing `wpdi_get_stats` response remains a flat legacy stats array.
- The existing cleanup identifiers and hooks remain. New operation metadata may be appended
  to results without removing old keys.
- `wpdi_health_score` remains the final compatibility filter over the lower-is-worse penalty
  score, even though the normalized report also explains category penalties.
- New scans use conservative limits and expose truncation/warning metadata rather than
  silently exhausting resources.

## Official API verification

WordPress 7.0 provides the provider-neutral `wp_ai_client_prompt()` builder. Text support is
checked with `is_supported_for_text_generation()` and text is generated with
`generate_text_result()`. The Connectors screen owns provider credentials and provider
selection. SAC therefore consumes the AI Client only and does not register a provider or
connector.

## Phase log

- 2026-08-09: Phase 1 audit completed at revision `9d47690`. Baseline PHP execution was not
  available on the host; container-based validation remains planned.
- 2026-08-09: Phases 2-4 completed. The bootstrap now loads focused ownership, report,
  redaction, and admin services. The report exposes runtime/storage/maintenance health,
  plugin footprints, table ownership, and legacy-compatible flat statistics.
- 2026-08-09: Phases 5-7 completed. Ghost findings are read only; autoload inspection is
  paginated and protected; mutations are batched and safety-snapshot backed. Options and
  orphan metadata support restoration, while post/comment deletion snapshots are audit-only.
- 2026-08-09: Phases 8-9 completed. JSON/CSV/HTML share the normalized report and central
  redactor. Optional AI uses only WordPress 7.0's provider-neutral prompt builder and exposes
  no abilities or mutation callbacks.
- 2026-08-09: Phases 10-11 completed for automated checks. WordPress Core/Docs/Extra and
  PHPCompatibility pass with zero findings; PHPUnit passes 5 tests/15 assertions on PHP 7.4;
  Plugin Check 2.0.0 passes a `.distignore` production build with no errors; WordPress 7.0
  activation and runtime snapshot/protected-option/AI-unavailable smoke tests pass.

## Resulting components

- `WPDI_Database_Inspector`: dependency loading and admin bootstrap.
- `WPDI_Report`: normalized metadata report, deterministic health, bounded indexes, and
  compatibility statistics.
- `WPDI_Ownership`: installed-plugin inventory, owner status, confidence, exact-prefix
  evidence, core tables, and protected options.
- `WPDI_Cleanup`: allowlisted 100-row mutation batches and guarded autoload changes.
- `WPDI_Snapshots`: non-autoloaded local retention, metadata listing, and supported restore.
- `WPDI_Redactor`: the shared export/AI sensitive-data boundary.
- `WPDI_Exporter`: report-only JSON, CSV, and HTML serialization.
- `WPDI_AI`: feature-detected WordPress AI Client support and metadata-only explanations.
- `WPDI_Admin`: backward-compatible hooks/AJAX plus progressive-disclosure UI.

## Verified WordPress sources

- `wp_ai_client_prompt()`:
  <https://developer.wordpress.org/reference/functions/wp_ai_client_prompt/>
- `WP_AI_Client_Prompt_Builder` support/generation behavior:
  <https://developer.wordpress.org/reference/classes/wp_ai_client_prompt_builder/>
- WordPress 7.0 Connectors API:
  <https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/>
- Plugin Directory guidelines:
  <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>

## Compatibility changes

No existing hook, filter, AJAX action, menu slug, cleanup identifier, or flat statistics key
was removed. Cleanup is now limited to 100 records per request and reports a snapshot ID;
this intentional behavioral tightening prevents memory exhaustion and unbounded destructive
requests. Snapshot data is the only new persistent option and is removed on uninstall.

## Known limitations

- Footprints are estimates and intentionally undercount when evidence is weak or an artifact
  falls outside the 10,000-option/1,000-meta-key scan caps.
- A missing-plugin finding requires an exact curated prefix; SAC cannot safely infer every
  historical plugin from arbitrary names.
- Table sizes and custom-table footprints are unavailable on restricted `information_schema`
  hosts.
- Automatic rollback is limited to options, network transient option rows, and orphan
  metadata. Post/comment cleanups are audit-only snapshots.
- The filesystem section explicitly reports “not scanned”; database inspection does not need
  broad filesystem access.
- AI results depend on a site-configured WordPress connector/provider and require manual
  staging tests with that provider.
- WooCommerce-scale, restricted-host, multisite-network, large-database timeout, and each AI
  provider scenario remain manual staging tests before publishing.
