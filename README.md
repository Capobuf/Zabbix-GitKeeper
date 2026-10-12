# Zabbix GitKeeper

**Version, audit, and recover your Zabbix configuration using GitHub.**

> **Project status: experimental implementation; partial live verification on Zabbix 7.4.15, full integration not yet verified.** The first iteration implements the PHP module, local worker, GitHub App Device Flow, private repository selection/creation, scheduled secret-filtered snapshots and status reporting. The specification below also describes future features. No Zabbix/Linux installation profile is certified and no restore/disaster recovery is implemented. See [installation](docs/installation.md), [coverage](docs/coverage.md), [architecture](docs/architecture.md) and [validation](docs/validation.md) for the actual scope and evidence.

**GitHub App configuration:** [Setup guide for maintainers and Zabbix administrators](docs/github-app.md). A single shared GitHub App is registered once; individual installations use Device Flow. No alternative GitHub authentication method is planned.

**GitHub App configuration:** [Setup guide for maintainers and Zabbix administrators](docs/github-app.md). A single shared GitHub App is registered once; individual installations use Device Flow. No alternative GitHub authentication method is planned.

Zabbix GitKeeper is a native Zabbix frontend module plus a lightweight local worker. After installation, an administrator connects a GitHub account, chooses or creates a **private** repository, selects the configuration categories to track, and saves. GitKeeper performs the first export automatically and continues on a configurable schedule. Errors, last run, and repository status are visible inside Zabbix.

The product has **two complementary capabilities**, which must not be confused:

1. **Configuration tracking:** human-readable, deterministic, secret-safe Git commits of the Zabbix configuration. Supports inspection, diffs, and selective/portable restore where the Zabbix API permits.
2. **Disaster recovery:** a separately scheduled, **encrypted** recovery package (database and explicitly supported local configuration files) stored as GitHub Release assets rather than ordinary Git commits. This is required to recover state that Zabbix APIs cannot export.

A successful Git export **does not** by itself mean that a fresh Zabbix installation can be fully restored.

## Goals

- Install on the Zabbix Linux VM **without Docker** or a permanently running application server.
- Appear as an official-style Zabbix **frontend module**, without modifying Zabbix core.
- Provide an administration-only **GitKeeper** page, preferably under **Administration**.
- Provide **native GitHub authorization**, repository discovery and selection; offer guided repository creation (direct creation when permissions permit).
- Let the administrator enable/disable export categories, with **All supported categories** as the default.
- Start an initial snapshot after **Save & sync**, then run automatically on a configurable schedule.
- Export the complete *user-managed* configuration **to the extent supported by each Zabbix API**, explicitly reporting unsupported or unreadable fields.
- Support restoring a new, clean Zabbix instance:
  - **Portable configuration restore** for supported API-managed objects.
  - **Full instance recovery** from an encrypted disaster-recovery package, with documented prerequisites and limitations.
- Report failures, incomplete coverage, skipped secrets, and recovery readiness directly in the UI.
- Preserve a minimal implementation, proportional testing, and upgrade safety.

**Non-goals:** GitHub-to-Zabbix continuous two-way synchronization, replacing Zabbix monitoring history storage, backing up arbitrary parts of the operating system, silently migrating across arbitrary Zabbix/database versions, or guaranteeing recovery of external services (LDAP, SAML IdP, Vault, proxies/agents, SMTP relays) that GitKeeper does not control.

## User experience

### 1. Install and activate

Install the release package on a supported Linux host that runs both the Zabbix server and frontend. The installer places the PHP module in the **existing Zabbix frontend `modules/` directory**, installs the worker and creates a cron entry. In **Administration → General → Modules**, the administrator scans the directory and enables **Zabbix GitKeeper**.

This is the supported Zabbix module registration flow, **not** a claim that Zabbix can upload and install arbitrary ZIP modules directly from its web UI. Split frontend/server deployments require a separately designed installation mode and must not be treated as local installations.

### 2. Connect GitHub

From **Administration → GitKeeper** (preferred location; use the closest officially supported administration navigation integration on each tested Zabbix version), select **Connect GitHub**.

**Preferred authentication:** a registered **GitHub App**, using the official **OAuth device authorization flow**, with a public client ID and **no shared app private key or client secret embedded in the distributed module**.

1. The page displays a GitHub verification URL and a short-lived user code.
2. The administrator authorizes GitKeeper on GitHub and installs/grants the GitHub App access to a chosen account and repository or repositories.
3. GitKeeper confirms the GitHub identity, authorized installation, and effective repository permissions.
4. GitKeeper securely stores the resulting user access/refresh token material on the VM; the token is never displayed or committed.
5. Credentials refresh automatically where applicable; revoked/expired access must result in a visible **Reconnect GitHub** state.

Each installation can use its own registered GitHub App. Save its **Client ID** in **Administration > GitKeeper > GitHub**, enable device flow and configure narrowly scoped permissions. Use **Metadata: read** and **Contents: read/write** for repository access. GitHub App user tokens are constrained by **both** user and installation permissions. Do not distribute the app's signing private key. Implement token refresh according to GitHub's documented device-flow rules; do not assume every token is permanent.

### 3. Select or create the destination

- List repositories **actually accessible** to the authorized GitHub App and current user. Show account, repository, privacy, access status, and the selected branch.
- In the GitHub tab, show the repository table and selector beside the dedicated private-repository creation form. **Use repository** saves the destination before API setup; changing it pauses synchronization until **Save & Sync**. Creation supports a personal account or organization and selects the new repository only after access verification.
- Offer **Create private repository** using the GitHub App **Repository creation: write** permission when granted. Do not request **Administration: write** solely to create backup repositories. Verify installation access to the newly created repository separately; otherwise guide the user to create it on GitHub and grant App access.
- As a reliable first fallback, provide **Create on GitHub**, then refresh the repository list after GitHub App installation/access has been granted.
- **Private** repositories are required for backup data. Reject a public target rather than silently publishing internal monitoring topology, user identities, and endpoint names.
- Recommend a dedicated branch (for example `gitkeeper`); use an existing branch only after verifying write access and branch protection. Never force-push.

### 4. Choose what to export

The page offers **All supported categories**, plus independent checkboxes for:

| Category | Expected contents |
| --- | --- |
| Hosts and groups | Hosts, interfaces, tags, host groups, inventory, host macros, template links and host-specific settings |
| Templates and monitoring | Templates, items, triggers, dependencies, preprocessing, discovery rules/prototypes, web scenarios, graphs, value maps |
| Alerts and notifications | Actions, conditions, operations, escalations, media types, message templates and related recipients |
| Users and access | Users, user groups, roles, permissions, media assignments and user-related settings |
| Discovery and automation | Network discovery, autoregistration, global correlations and scripts |
| Infrastructure | Proxies, proxy groups and relevant monitoring configuration |
| Operations | Maintenance windows, services, SLAs, dashboards, maps and scheduled reports |
| Administration | Global macros, regular expressions, settings, authentication-related settings and connectors |

This is the **target coverage inventory**, **not** a promise that a single Zabbix API method exports every property. Implementation must maintain a **per-version coverage matrix** with status `exported`, `redacted`, `not-readable`, `not-supported` or `failed`. No category may be marked complete based merely on successful API responses.

The administrator selects the cadence for **Git snapshots** (suggested default: 15 minutes) and separately, if enabled, the cadence for **encrypted recovery packages** (suggested default: daily). A full-database recovery package is always **whole-instance**; per-category checkboxes apply to the readable API snapshot, not to a database dump.

### 5. Save & sync

- Validate GitHub access, repository privacy/write permission, Zabbix API access and the requested categories.
- Save settings and enqueue the **first sync automatically**.
- The local worker is awakened by a **one-minute cron tick**, so first sync starts on the next tick; the UI shows `Queued` → `Running` → `Completed` / `Failed`, without requiring a browser request to remain open.
- Display **last attempt**, **last successful complete snapshot**, **last Git commit**, **next scheduled run**, **category results** and **warnings/errors**.
- Show useful actionable errors (auth revoked, API permission denied, repo protected, rate limited, incomplete export, missing recovery key, archive too large), with secrets removed.
- Retrying an unsuccessful run must not overwrite or delete the previous valid snapshot.

### Administration page

Keep the native Zabbix look and interaction patterns. Prefer one page with four small sections/tabs: **Overview**, **GitHub**, **Backup scope & schedule**, **Restore**. Avoid an additional web framework.

Actions: **Connect / Disconnect**, **Test connection**, **Save & sync**, **Sync now**, **Open repository**, **View last error**, **Check recovery readiness**, and **Start guided restore** (only where safe). Restrict configuration changes and restoration to authorized **Zabbix Super admins**. Respect Zabbix sessions and CSRF protections.

The page must visibly distinguish:

- **Git snapshot healthy** — all selected readable categories exported and pushed.
- **Portable restore readiness** — supported object coverage and missing secrets listed.
- **Full recovery ready** — an encrypted package exists, checksum and manifest are valid, and required key/prerequisites are recorded.
- **Incomplete / degraded** — no false green status if some selected objects or dependencies were omitted.

## Technical architecture

```text
Zabbix frontend (native PHP module)
  └─ GitKeeper settings, GitHub authorization UI, status and restore guidance
       └─ local restricted command queue + status files
            └─ cron (every minute)
                 └─ Python worker (runs only when a job is pending/due)
                      ├─ Zabbix JSON-RPC API → normalized snapshots
                      ├─ native Git client → commits and push
                      ├─ database backup tools (optional recovery mode)
                      ├─ encryption → GitHub Release assets (recovery mode)
                      └─ redacted status/logs → frontend
```

- **PHP module:** official Zabbix module manifest, action/controller/view structure, registered menu entry and access controls. No core patches.
- **Python worker:** local virtual environment; prefer the official `zabbix-utils` library; use system `git`. No Docker, long-lived worker, webhook endpoint or required external orchestration service.
- **Scheduling:** one lightweight minute-based cron entry reads configured intervals and queued manual actions. Use a single-instance lock, bounded retries and backoff.
- **Communication:** small local, atomic, permission-controlled request/status files (or another equally simple, audited local IPC mechanism). The PHP web process must **not** run arbitrary shell commands or obtain database root credentials.
- **State:** separate operational state from versioned data. The worker's credentials, locks and logs must remain outside the frontend document root and Git working tree.
- **Zabbix API:** dedicated account/token with the smallest working Zabbix role. Some categories may need Super admin access; surface those privilege requirements explicitly rather than silently skipping them. Never store a Zabbix login password when a properly scoped API token is sufficient.
- **Deployment:** initial support targets **Zabbix 7.0 LTS and 7.4**, on explicitly tested Linux package installations. MySQL/MariaDB and PostgreSQL are both target database engines for disaster recovery, subject to successful integration tests. The exact distribution/version matrix must be published after testing.
- **Installer:** detect the actual Zabbix frontend directory, Python/database tool availability, permissions and cron implementation; install idempotently; provide upgrade/uninstall paths that preserve local state and do not modify Zabbix core. Exit with a clear error on unknown layouts.

Suggested filesystem split:

```text
/usr/share/zabbix/modules/gitkeeper/      # Example only: detect the real Zabbix modules path
/opt/zabbix-gitkeeper/                    # Worker and virtual environment
/etc/zabbix-gitkeeper/                    # Restricted configuration and credentials
/var/lib/zabbix-gitkeeper/                # Git checkout, queue and run state
/var/log/zabbix-gitkeeper/                # Restricted, rotating logs
```

## Versioned configuration format

Use Zabbix's official `configuration.export` for the object families it supports, **plus dedicated read APIs** for the rest (for example `action.get`, `user.get`, `usergroup.get`, `role.get`, `maintenance.get` and `settings.get` as applicable).

Persist stable, readable JSON or YAML with documented schemas; normalize semantically unordered collections, use meaningful file names plus stable identifiers to avoid collisions, remove volatile export timestamps, and preserve order when the order affects Zabbix behavior. Avoid committing opaque raw API responses without review: database IDs are instance-local and may need relationship resolution for portable restore.

Example **destination repository** (different from this source-code repository):

```text
zabbix-config-backup/
├── README.md
├── manifest.json                 # Export schema, Zabbix version, coverage, checksum, completeness
├── native/
│   ├── templates/
│   ├── hosts/
│   ├── groups/
│   ├── maps/
│   └── media-types/
├── api/
│   ├── actions/
│   ├── users/
│   ├── roles/
│   ├── maintenance/
│   ├── discovery/
│   ├── services/
│   ├── proxies/
│   └── administration/
└── recovery/
    └── index.json               # References encrypted Release assets, never plaintext dumps
```

Git rules:

- **One successful run = at most one commit**; commit only if deterministic exported content changed.
- Stage a complete export first; **publish atomically** only after all selected categories succeed. Fail closed on partial exports. Explicitly excluded/not-readable fields are represented in the manifest and UI, not mislabeled as successful exports.
- Remove obsolete managed files only after a **verified complete enumeration** of that category; never infer deletion from timeout, pagination failure or empty/error response.
- Never force-push, silently reset history, or overwrite unrelated files in the selected repository.
- Track the source Zabbix version, snapshot schema version, UTC export time **in commit/manifest metadata without creating a needless commit on unchanged configuration**, and the producing GitKeeper version. The mechanism must avoid a changing timestamp in tracked files when there is no real configuration change.
- Keep authentication tokens, raw secrets, passwords, PSKs and encrypted recovery **private keys** out of normal commits. Redact sensitive fields before displaying diffs, logs or error messages.

## Backup versus restore: explicit guarantees

### A. Portable configuration restore (API-based)

Goal: restore as much user-managed configuration as possible onto a clean, compatible Zabbix instance **without overwriting its entire database**.

- Read the Git snapshot and validate schema/Zabbix version, checksums, coverage and dependencies.
- Show a **dry-run plan** listing objects to create/update, unresolved references, missing sensitive values and unsupported categories.
- Restore in dependency order (groups/roles/users and common dependencies, templates, hosts, actions/media, operational objects); resolve relationships rather than reusing source database IDs blindly.
- Use official `configuration.import` where appropriate and dedicated create/update APIs elsewhere.
- Imported users must have their identity, roles, group membership and notification assignments reconstructed where supported. **Original local passwords and API token values cannot be reconstructed from the ordinary API export**; require secure password reset/token regeneration and show those steps before claiming success.
- Secret-text macros are **write-only** in Zabbix's API. Never pretend the API snapshot can recover their values; require re-entry or full recovery.
- By default, do not delete existing destination objects. Provide explicit conflict handling and operator confirmation for destructive updates.
- Every attempted object must have a result; final report lists successful, skipped, failed and manually required items.

**Portable restore is not equivalent to an exact instance clone.** It must never carry a misleading "full restore" label when unreadable secrets or unsupported entities remain.

### B. Full-instance disaster recovery (encrypted recovery package)

**Required for the product's exact-recovery objective.** Git commits alone cannot reproduce Zabbix users' passwords, internal state, secret macros and all server settings.

When enabled, GitKeeper creates a **consistent native database backup** of the **entire supported Zabbix database** and a deliberately defined set of **local configuration files** essential for the supported installation profile. Use proper MySQL/MariaDB or PostgreSQL tools and documented consistency guarantees; do not invent an unofficial "configuration-only" SQL dump by omitting tables without a validated restoration method.

- Encrypt the backup **before upload**, using a modern audited tool such as `age` and a **recovery public key** configured during setup.
- The **recovery private key** must be exported once to the administrator and kept **outside GitHub and outside the backup archive**. Losing it makes the encrypted package unusable.
- Publish encrypted packages as **private-repository GitHub Release assets**, not as large Git blobs; store a versioned manifest (source version, DB engine/version, files included, sizes, checksums and asset references) in the repository.
- Default to a **daily** recovery schedule, independent of 15-minute Git snapshots; expose retention and available storage clearly. Never erase the last verified recovery package.
- Include a safe, explicit allowlist of supported files (server/frontend connection configuration and other required local assets). Arbitrary external scripts, TLS material, custom modules and other host-local dependencies are **not** included unless specifically supported/selected and confirmed in the manifest.
- Before enabling, calculate approximate DB size and resource requirements. GitHub Release assets are limited to **less than 2 GiB per file**. Do not treat an oversized archive as successful; choose an explicitly implemented and tested multipart transfer or report that this backend cannot satisfy full recovery for the instance.
- Validate encryption, archive integrity, checksums and manifest metadata before marking a recovery point healthy.
- **No automated destructive database restore from the running Zabbix web page.** For a new clean instance, provide a separately installed **`gitkeeper restore` CLI/wizard**, with GitHub authorization, recovery key, version/database compatibility checks, preflight and explicit confirmation.
- Document how to prepare a **matching Zabbix/database environment**, stop the necessary services, preserve a safety copy, restore database and supported files, restart services, and run post-restore checks. Across-version migrations must follow **official Zabbix upgrade procedures**, not direct cross-version imports.
- Do not assume credentials for external IdPs, vaults, SMTP providers, proxies, agents, or custom scripts are recoverable unless their actual prerequisites were included. List everything that must be supplied after restore.
- The GitHub authorization used for GitKeeper itself should be re-established on the recovered host; it must not be silently transplanted as an unattended reusable credential.

**Recovery status contract:** `Full recovery ready` is shown only when a verified encrypted package exists and the manifest reports that all components required by the **supported recovery profile** are included. Otherwise display `Partial recovery / external dependencies` and the precise gaps.

This is **a configuration continuity and disaster-recovery tool, not a substitute for an independent offsite database backup strategy**. GitHub account/repository access, key custody, archive size, retention and external services remain real disaster-recovery dependencies.

## Security requirements

- Restrict UI access to authorized Zabbix administrators, enforce CSRF protections and use native Zabbix sessions.
- Restrict GitHub App installation to selected repositories. Never ship app secrets/signing keys in the source or release package.
- Store GitHub and Zabbix tokens outside the PHP document root, with strict filesystem permissions; redact all error output and logs.
- Keep the GitHub recovery destination **private**, and encrypt full recovery archives regardless of repository visibility.
- Never commit sensitive raw API export values without an explicit audited field-level sanitization policy.
- Avoid passing credentials as command-line arguments; do not expose secrets in process listings, logs or temporary files.
- Enforce HTTPS certificate verification, safe path handling, CSRF/authz checks and non-overlapping jobs.
- Failed push/auth/export/upload must be visible and must not invalidate an earlier good recovery point.
- Installation and updates may use elevated privileges, but the recurring worker must use a separate minimally privileged system identity.

## Recovery coverage and validation

A **coverage inventory is a deliverable**, not optional documentation. For every category and supported Zabbix version, record:

- Export API/method or native export type and whether each important field is available.
- Whether data is human-readable, redacted, and/or present only in encrypted disaster-recovery packages.
- How it can be restored, dependencies/order, and whether a test actually passed.
- Which external secrets, agents, services, certificate files or providers require post-restore intervention.

Acceptance tests must include representative:
- hosts and templates, custom triggers and inherited overrides;
- actions, recipients, media types (including one webhook), users, groups and roles;
- a masked secret macro and password/token re-creation warnings;
- maintenance windows, one dashboard/map, and global settings;
- a restore to a **fresh Zabbix instance with a matching source/database version**, demonstrating that recovered objects and permissions work;
- a **full recovery round-trip** of a tested database profile, including hashed local credentials and secret values present in the database, without ever logging or committing plaintext;
- error paths: revoked GitHub access, missing Zabbix permissions, broken export, corrupted archive, wrong recovery key, incompatible Zabbix version and interrupted upload.

Use isolated fixtures and a small disposable Zabbix/database test setup. Do not build a heavy end-to-end matrix for every platform until the corresponding platform is advertised as supported.

## Delivery milestones

1. **Foundation:** installable native Zabbix module, local cron worker, GitHub App device authorization, repository choice, settings and live status.
2. **Safe Git snapshots:** deterministic export of hosts/templates plus dedicated API collectors; category completeness accounting, redaction, Git commits and error handling.
3. **Full user-managed configuration:** fill the per-version coverage matrix, implement remaining available exporters and portable restore with visible exceptions.
4. **Disaster recovery:** encrypted native DB and local-config bundles, GitHub Release storage, retention, recovery-key UX and offline restore CLI; prove a full recovery round-trip.
5. **Release readiness:** compatibility/upgrade validation for advertised Zabbix and DB versions, installer/uninstaller, threat review and concise admin documentation.

Milestones are implementation order, **not a waiver of the final recovery requirement**.

## Definition of done

GitKeeper is ready for a release claiming **configuration tracking** when an administrator can install it, authorize GitHub, choose a private repository, select categories, save, observe an automatic first export, inspect meaningful Git diffs, configure subsequent runs and diagnose incomplete backups from the Zabbix page.

GitKeeper may claim **full-instance recovery** only for specific, published Zabbix/database/platform profiles after a documented successful **clean-instance restore test** of an encrypted recovery package. Until then the UI and README must state plainly that full recovery is **not yet supported**.

## References

- [Zabbix frontend modules](https://www.zabbix.com/documentation/current/en/manual/extensions/frontendmodules)
- [Zabbix module registration](https://www.zabbix.com/documentation/current/en/devel/modules/file_structure/register)
- [Zabbix `configuration.export`](https://www.zabbix.com/documentation/current/en/manual/api/reference/configuration/export)
- [Zabbix `configuration.import`](https://www.zabbix.com/documentation/current/en/manual/api/reference/configuration/import)
- [Zabbix API methods](https://www.zabbix.com/documentation/current/en/manual/api/reference)
- [Zabbix secret macro properties](https://www.zabbix.com/documentation/current/en/manual/api/reference/usermacro/object)
- [Zabbix upgrade and database backup guidance](https://www.zabbix.com/documentation/current/en/manual/installation/upgrade)
- [GitHub App user access token/device flow](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app)
- [GitHub App token refresh](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/refreshing-user-access-tokens)
- [GitHub repository creation permissions](https://docs.github.com/en/rest/repos/repos#create-a-repository-for-the-authenticated-user)
- [GitHub Release storage limits](https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases)
- [GitHub Git file-size limitations](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github)

## License

**Not yet selected.** Select a license before publishing redistributable releases or accepting external contributions.
