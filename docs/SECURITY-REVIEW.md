# Security, Privacy, and Performance Review

Review date: 2026-08-09
Version: 1.1.0

## Trust boundaries

The plugin page and every AJAX/export action require `manage_options`. AJAX requests use the
existing `wpdi_nonce`; exports use a dedicated admin nonce. Every mutation additionally
requires the `confirmed=1` server-side flag, passes an implementation allowlist, respects
`wpdi_read_only`, and validates its exact target. Nonces are CSRF controls and are never used
as authorization substitutes.

The normalized report contains metadata rather than raw database values. Export and AI paths
both pass through `WPDI_Redactor`. Option preview is a separate administrator-only request,
is capped at 2,000 bytes, does not deserialize or execute content, and omits values whose
names look sensitive.

## Reviewed vulnerability classes

- **SQL injection:** Values and LIKE inputs are prepared; sort direction, actions, table
  identifiers, and column identifiers are hard-coded allowlists. Dynamic placeholder lists
  are derived from core autoload values or bounded exact names.
- **Stored/reflected XSS:** Admin output is escaped for its HTML context. JavaScript inserts
  server and AI text with jQuery `.text()`. HTML exports escape the encoded report.
- **CSRF:** All AJAX handlers call `check_ajax_referer`; the export handler calls
  `check_admin_referer`. State changes require explicit confirmation as a second server-side
  condition.
- **Privilege escalation:** Page, AJAX, export, preview, AI, snapshot restoration, and
  cleanup all require `manage_options`.
- **Path traversal/file access/deletion:** The plugin accepts no path input and performs no
  filesystem scan, arbitrary file read, or file deletion. Export responses are streamed with
  a generated fixed-format filename.
- **Unsafe deserialization:** SAC never calls `unserialize()` on inspected values. It only
  detects serialization markers. Normal WordPress option APIs handle SAC's own snapshot
  option.
- **Sensitive-data exposure:** Reports are metadata-only; sensitive keys and recognizable
  token/private-key shapes are centrally redacted. Snapshot values never enter reports or AI
  context. Preview is access controlled, capped, and name-redacted.
- **AI leakage/actions:** AI is feature-detected, administrator initiated, provider neutral,
  and metadata only. No ability/tool, SQL, option, cleanup, file, or snapshot callback is
  registered with the model.
- **Snapshot exposure:** Snapshots are held in a non-autoloaded database option, have no
  public URL, are visible only as metadata to administrators, and are limited by age, count,
  and per-operation size.
- **Export leakage:** All formats use the same normalized report and redactor. Exports contain
  no snapshot record values or raw option values.
- **AJAX/REST:** Every registered AJAX action has nonce and capability checks. No REST route
  is registered.

## Destructive-operation decisions

Cleanup processes at most 100 targets per request. Reversible option and orphan-metadata
operations fail closed if their snapshot cannot be stored. Option autoload changes are denied
for an extensive protected set and for WordPress-like `wp_`, transient, theme-mod, and widget
prefixes. Missing-plugin and other heuristic ghost findings have no delete control.

Post and comment removal uses WordPress APIs. Its snapshot is audit-only because safe generic
restoration would require rebuilding related state and hooks. Object-cache flushes cannot be
snapshotted and are performed only when an external object cache is detected.

## Performance controls

- UI tabs load plugin/ghost artifact aggregation only when requested.
- Autoload rows use SQL pagination, a maximum page size of 100, and no full values.
- Cleanup and snapshots are bounded to 100 targets and 1 MB per operation.
- Plugin artifact indexing caps options at 10,000 recent names and metadata at 1,000 grouped
  keys per supported metadata table; estimates explicitly may undercount.
- Table sizes use one `information_schema` aggregate and degrade to an unavailable warning.
- AI context caps each detailed collection at 50 entries.
- No recursive filesystem scanning or background job is introduced.

## Known limitations requiring environment testing

The security controls were statically checked and smoke-tested on a clean WordPress 7.0 site.
Host-specific SQL modes, very large production schemas, multisite network topologies, unusual
object-cache drop-ins, and provider-specific AI behavior still require staging validation.
