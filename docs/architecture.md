# Architecture and security boundary

The native PHP module registers Administration → GitKeeper using Zabbix's menu API and manifest 2.0.
All controllers require Super admin. Mutations use default native `CController` CSRF checking and POST
forms with the exact module action token. Read-only view/status actions disable CSRF checks.
The frontend uses native Zabbix components, with a small read-only status polling script.
The page has two tabs (Panoramica and Impostazioni); setup is derived from public worker state.
Native POST forms retain CSRF and redirects. A single visibility-aware polling loop updates status,
category counts, authorization codes and repository options without automatic page reloads or loss of edits.
The latest snapshot outcome (`last_sync`) is independent of `last_result` for other operations.
`current_request` identifies a request after the secret-bearing queue file is claimed; none of these
public summaries includes parameters or credentials. Only latest outcomes are retained, not job history.

PHP writes atomically renamed JSON requests; it never invokes a shell. A native systemd path unit
watches finalized `queue/*.json` files and starts the worker immediately. The one-minute cron starts
the same oneshot service for authorization polling, token refresh and scheduled exports. Systemd
serializes these starts; requests published during a running export remain queued and reactivate
the service when it exits. The existing POSIX `flock` also protects manual invocations and upgrades.
Each tick processes up to 20 requests, then polls authorization, refreshes tokens and runs a due/manual
export. No HTTP service, permanent worker process, application database or core frontend patches.
Only explicit operations and exact parameter sets are accepted independently by PHP and Python.
The installer requires running systemd and installs/enables the path unit automatically on every
supported host. PHP needs no shell execution, sudo rule, service-management permission or extra setting.
The service runs as `gitkeeper` and has no startup timeout, allowing large exports to finish.
See the native [path activation semantics](https://github.com/systemd/systemd/blob/main/man/systemd.path.xml).

## Filesystem identities

The worker runs as `gitkeeper`, with a private primary group. The separate `gitkeeper-ui` group permits
PHP to write requests and read public status. Worker executables/module files are root-owned;
`private/` is `0700`. Queue requests are created by PHP with mode `0240`: the owner can write but cannot
read; the worker reads via the shared group. PHP briefly knows a newly submitted API token in request
memory, but cannot read the persistent worker copy or its own finalized token submission. Requests are
moved into `private/` before reading and deleted after processing. The worker checks regular-file type,
link count, size, filename/request ID and the command whitelist; symlink submissions are rejected.
Public status contains only settings, repository metadata, device user code, counts and fixed safe errors.

This protects credentials against ordinary frontend filesystem reads, not against a compromised root,
worker account or administrator able to issue authorized commands. Unix directory ownership/group
assumptions are exercised in the Linux CI fixture, and still need validation on the deployment profile
(including SELinux/AppArmor and PHP `open_basedir`). Pending requests survive uninstall; treat them as
confidential local data. No persistent credentials are stored in the document root or Git working tree.

## Export and publication

The official `zabbix-utils` client checks `apiinfo.version` before using a token. The reviewed target
series are 7.0 and 7.4; unknown versions stop. A Super-admin-only probe prevents silently filtered
visibility. API getters are unbounded by `limit`: count-before/read/count-after and unique IDs detect
truncation/count changes. Zabbix has no universal offset pagination for these getters, so no invented
pagination parameters are used. Native exports are batched one host/template per call. Native items,
triggers, discovery rules and item/trigger prototypes are cross-checked with separate getters. No API
transaction exists; same-count concurrent edits across categories cannot be proved absent. Avoid
changing configuration while collecting snapshots. Graph/host prototypes are retained by native export
but not independently counted; this limitation is explicit in coverage.

Context-specific serialization policies preserve readable configuration and credentials, including
text macro values, Vault references, item keys, expressions, SNMP/HTTP credentials, preprocessing,
scripts, inventory, web scenarios, descriptions and webhook parameters. Payload subtrees retain all
returned fields and array order; unclassified fields in explicitly reviewed structures still stop
the snapshot. Native export timestamps are removed to avoid commits for unchanged configuration.
Secret-text macro values missing or empty in the native export are marked as unreadable and listed
in the manifest; readable values are never replaced with a confidentiality placeholder. Vault references
are preserved, but resolved Vault secrets are not returned by native export. User passwords, API tokens
and MFA secrets are not collected. Worker authentication tokens remain outside snapshots and public status.
Snapshots contain plaintext credentials returned by Zabbix and must remain in the private repository.
Manifest schema 2 identifies this readable-credentials policy; schema 1 snapshots used broad redaction.

Each export is prepared in a worker-only temporary directory. All selected categories must succeed
before a deterministic manifest with checksums is produced. Only then does Git create a temporary
worktree from the fetched default branch, replace its verified managed `gitkeeper/` area, and publish
at most one commit. An unchanged tree creates no commit. A failed export/push leaves the prior checkout
and remote snapshot intact. Foreign files/symlinks, dirty checkout, divergence and protected branches
are refused. No force push. Detached failed publication commits are unreachable locally and may be
garbage-collected by Git; they are never reported as successful.

Git uses a clean HTTPS remote URL and a root-owned `GIT_ASKPASS` script. Tokens exist only in the child
Git environment, never arguments, URLs or Git config. Git terminal prompts, global/system credentials,
hooks and commit signing are disabled for these operations. Errors are fixed messages because both API
and Git errors can contain confidential or attacker-controlled strings. UI values are escaped by native
components, and polling uses `textContent`.

OAuth calls follow only fixed GitHub endpoints and reject redirects. User tokens refresh without a
client secret. Expired/revoked/rejected tokens clear local authorization and require reconnecting;
transient refresh failures have a capped backoff. GitHub installation/user permissions and repository
privacy are rechecked before every publication, though privacy can still be changed outside GitKeeper
after a snapshot was uploaded. Restrict destination administration accordingly.

## Explicit limits

No portable restore, database dump, encryption/recovery package, multi-instance mode or complete
user-managed configuration inventory is implemented. Snapshot JSON must not be blindly
fed to `configuration.import`: unreadable markers and the snapshot envelope require a future schema-aware restore tool. No full-recovery
readiness is claimed. Lower-privilege API roles and remaining categories are
future coverage work. Installation detection and live frontend behavior still require deployment tests.

References: [Zabbix module manifest](https://www.zabbix.com/documentation/7.0/en/devel/modules/file_structure/manifest),
[official Python client](https://github.com/zabbix/python-zabbix-utils),
[7.0 export schema source](https://github.com/zabbix/zabbix/blob/release/7.0/ui/include/classes/import/validators/C70XmlValidator.php),
[7.4 export builder](https://github.com/zabbix/zabbix/blob/release/7.4/ui/include/classes/export/CConfigurationExportBuilder.php).
