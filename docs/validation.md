# Validation evidence and remaining acceptance checks

## Readable configuration and credentials, 2026-10-12

The export policy now preserves previously omitted readable configuration and credentials:
item keys, trigger expressions, preprocessing, text macros, Vault references, inventory, web scenarios,
action conditions/messages, media-type credentials/scripts/parameters and user-media recipients.
Missing/empty secret-text macro values retain an unreadable marker; resolved Vault secrets are not
provided by native export. Worker authentication tokens remain outside snapshots and public status.
Manifest schema 2 identifies this policy, and publication accepts existing schema-1 repositories.
Earlier redaction evidence below describes the previous policy and does not establish live acceptance
of the new payload coverage.

Local checks: Ruff passed; Python ran 44 tests successfully (one POSIX lock test skipped on Windows);
PHP view lint, frontend controller/queue contracts, frontend polling regressions and `git diff --check`
passed. Fixtures exercise 7.0/7.4 readable payloads, unreadable macro markers, contextual macros,
deterministic export and schema migration. A real local Git regression verifies that changing only
an action message creates a commit. No live Zabbix check or GitHub push was performed for this change.

Local development environment: Windows, Python 3.12, PHP CLI and native Git. Initial automated
validation used synthetic data. Subsequent read-only checks used the supplied Zabbix 7.4.15 instance
on Ubuntu 24.04.5, Apache 2.4.58, PHP 8.3.6 and Python 3.12.3. Neither target series is certified.

## Partial live verification, 2026-10-11

- Copied the frontend module to the verified `/usr/share/zabbix/ui/modules/gitkeeper` directory,
  scanned and enabled it using Zabbix's native Modules page, and opened Administration > GitKeeper
  in the existing Super admin browser session. This initial deployment was frontend-only.
- Reworked the page into native Overview, GitHub, Zabbix API and Configuration tabs. Verified each
  tab renders; form controls have unique IDs and labels point to existing fields. Unavailable worker
  actions are disabled. The installed module is root-owned and not writable by Apache's `www-data` user.
- Added a worker installation command and native Copy button to Overview when worker status is
  unavailable. Confirmed the rendered command uses `/usr/share/zabbix/ui` and `www-data`; its Bash
  syntax passed on the server and PHP contracts passed locally. The button showed its copy confirmation
  without JavaScript errors. Browser automation uses a separate virtual clipboard, so it could not
  independently read back the operating system clipboard. The worker was not installed at this stage.
- Prefilled the API URL from the native frontend address on the live instance:
  `http://172.16.100.7/zabbix/api_jsonrpc.php`. Verified that manual edits propagate between both
  token forms and HTTP is explicitly flagged without guessing HTTPS. The UI displays frontend version
  7.4.15. Local endpoint tests cover ports, subdirectories and rejection of embedded credentials/query
  strings. Worker tests cover restoring the verified endpoint after restart, clearing it on token removal,
  and keeping token material out of public status. Single-repository preselection is implemented but has
  not been tested against a live App because no App Client ID is available. Saved settings take precedence.
- Authenticated the official `zabbix-utils` client with a temporary administrator login and exercised
  the actual collectors. Passed actions (5 objects), media types (39), users/groups/roles (12), and
  hosts/groups (26: 15 hosts and 11 groups). Temporary sanitized exports were deleted; no backup data
  was pushed. This does not verify existing-token or native-token creation workflows. The full
  364-template check was interrupted after several minutes and is not recorded as passed.
- The first host check exposed generated nested LLD rules being compared with configuration-only
  native exports. Restricting root rules to flags 1 and configured 7.4 discovery prototypes to flags 3
  fixed that mismatch. Added a regression that also rejects a missing configured nested prototype.
- Created the private destination repository, initialized with a README. Device Flow,
  App installation permissions and GitHub publishing have not been exercised. The supplied endpoint
  is HTTP and port 443 is not listening; the worker requires trusted HTTPS. No TLS configuration was performed.
- After these changes, lint and 26 local Python tests ran (25 passed; one POSIX lock test skipped on Windows),
  as did PHP controller/queue contracts and view syntax checks. Updated Linux CI has not been run.

## GitHub App configuration and worker installation, 2026-10-11

- Added the native GitHub App Client ID field and Save GitHub App form. Installed the current local
  worker and frontend source on the supplied server using the actual installer, without an App ID or
  placeholder credentials. Installed Ubuntu's `python3-venv` prerequisite, validated Apache configuration
  and reloaded Apache to pick up the UI group. Manual ticks and subsequent idle cron ticks wrote clean
  status; no GitHub authorization, API token or export was configured.
- Verified PHP can write the queue but cannot read private state or edit worker/frontend executables.
  The worker's status exposes the non-secret Client ID, and no token material. Source staging was removed.
- Verified the field and enabled Save button in the actual frontend. An empty Client ID POST was
  rejected and redirected to the native error notification. This exposed pre-existing calls to absent
  redirect-message methods in Zabbix 7.4; replacing them with the actual CMessageHelper fixed the HTTP 500.
- Local lint and 30 Python cases ran (29 passed, one POSIX skip on Windows), with PHP contracts/lint.
  Fixtures cover App changes clearing prior authorization/scheduling, idempotent saves, persistence,
  post-lock reload and rejecting an unconfigured App before network access. The real Client ID has not
  been entered; successful App configuration/OAuth and a GitHub snapshot remain unverified. Updated Linux
  CI and the isolated root installer smoke test have not been run for these changes.

For a full read-only collector check, run from the source root. It prompts for a password, uses an
ephemeral API login, stops at the first failed category and deletes temporary exports:

```sh
python scripts/check-live-export.py --url http://172.16.100.7/zabbix/api_jsonrpc.php --user Admin
```

Use `--categories hosts actions media_types users` for the shorter scope. The full template inventory
can take substantially longer. HTTP is accepted only by this explicit diagnostic client; the worker's
HTTPS requirement remains in place. No GitHub push, persistent token or monitoring configuration
change is performed by this command.

## GitHub connection failure visibility, 2026-10-12

- Reproduced Connect GitHub with the installation's saved numeric value. The native POST queues the
  request, the worker reaches GitHub, and device authorization returns HTTP 404. The GitHub tab stays
  selected after the redirect and now shows the worker state and actionable error on that same tab.
  No authorization code or token was issued. The saved value was preserved pending the actual Client ID.
- Added a numeric-value reminder without enforcing an assumed Client ID prefix. After account
  verification, the owner's native GitHub settings showed App ID `5277227`, a distinct Client ID,
  and Device Flow enabled. Saved that observed Client ID through the native Zabbix form and processed
  the request with the installed worker. The next Connect GitHub request successfully obtained a
  device authorization code, displayed in the GitHub tab with no error. The code remains visible
  across status polls. After the user authorized the code, cron completed OAuth and public status
  showed account `Capobuf`, GitHub `Ready`, no device code and no error. A read-only request made with
  the worker's authorization returned an empty `/user/installations` list; repository access remains
  unavailable. No token value was displayed or included in evidence.
- Normalized the initial PHP JSON fingerprint before comparison with JavaScript status JSON. Previously,
  slash escaping in a pending device URL would trigger a reload on every poll even without a state change.
  The Node regression runs the actual polling asset, checks unchanged pending authorization does not
  reload, new codes do reload, and errors update in the GitHub panel. The real pending-code screen was
  also observed after correcting the installation's saved Client ID.
- Local Node regression, lint, PHP contracts/view lint and 32 Python cases ran successfully (31 passed,
  one POSIX skip on Windows). Updated Linux CI has not been run.
- Corrected the post-authorization UI: the connected screen now confirms successful authorization,
  gives installation/access guidance when no eligible repository is returned, and disables Connect
  while connected or awaiting authorization to prevent resetting the flow. The Client ID remains
  visible and editable in the GitHub tab. Verified the deployed page shows Connected, Ready and Capobuf.
- Fixed deferred polling refresh: previously a changed fingerprint observed while a field had focus
  was consumed without refreshing the form after focus left. Keep the rendered fingerprint until
  reload, while updating the live connection/account/error text. The Node regression verifies this
  transition and preservation of focused input; PHP view lint and contracts pass locally and the
  deployed view passes PHP lint. Full export/synchronization remains unverified.

## Repository management UI, 2026-10-12

- The GitHub tab now contains the native repository table (name/link, privacy, write access and default
  branch), destination selector and dedicated private-repository creation form. Owner is prefilled from
  the selected destination or authorized account. Configuration displays the selected destination.
- Added a validated `select_repository` queue operation. It verifies App/user/private access before
  persisting the destination, works before Zabbix API setup, and pauses an existing schedule when the
  destination changes. Snapshot metadata from the previous destination is cleared; selecting the same
  destination preserves its schedule. Selection alone does not start export.
- Direct creation supports native `/user/repos` and `/orgs/{owner}/repos` endpoints. Requests force
  privacy and README initialization; only a repository that passes App-access verification is selected.
  Real creation has not been attempted; mock tests cover personal/organization endpoints, access denial,
  private creation and selection without export. No extra repository was created for this UI check.
- Ran 36 Python cases (35 passed, one POSIX skip on Windows), Ruff, PHP contracts/lint and Node polling
  regressions. After clearing previous-target metadata, reran the affected selection regression and Ruff.
  Deployed the worker/view/controllers/queue files, verified installed worker hashes and native rendering:
  connected account, empty repository table, disabled selector action, prefilled owner and no duplicate IDs.
- Read-only worker API still returns zero accessible installations. GitHub's actual Install App page
  offers only Capobuf; Advanced shows Make public / Allow this GitHub App to be installed on other accounts.
  The App is private and owned by Capobuf, so the target organization is not an available installation target. Native
  permissions show Contents and Repository creation set to Read and write. The user chose to make the
  App public; GitHub's confirmation dialog blocked browser automation and was handed to the user.
  After the dialog closed, Advanced still displayed Make public: public visibility is not yet verified.
  No ownership, installation or permission change was performed.
  Repository access and completed live selection/creation remain unverified.

## Clearer setup messages and organization access, 2026-10-12

- Simplified the native UI into three numbered GitHub steps, contextual next-step guidance and
  operation-specific submission/rejection messages. Repository refresh is beside the list; Connect
  is absent while connected or awaiting authorization. App-change details use native HTML disclosure.
  Zabbix API now shows its configuration state, and Configuration explains missing prerequisites.
- Deployed the view/controller to the supplied server. PHP lint and controller/queue contracts, the
  Node polling regression and diff whitespace checks passed. No worker logic changed in this UI pass.
  Verified connected and empty-list rendering, the actual refresh notification, API HTTP guidance and
  disabled Save & Sync with a missing prerequisite in the real Zabbix frontend.
- After the user completed Make public, verified Advanced displays Make private and the target organization is an
  available installation target. Prepared Only select repositories with the private destination repository, obtained
  explicit installation confirmation and observed GitHub's successful installation notification and
  saved single-repository access. Permissions are metadata read and contents/repository creation write.
- Refreshed repositories through the native GitKeeper form and processed the request using the
  installed worker. The private destination repository (main) appeared automatically, preselected as
  the only available destination. Used the native Use repository button; the worker saved the verified
  destination with no error and synchronization still inactive. No extra repository or snapshot was
  created. The Zabbix API remains unconfigured and the installation's endpoint is still HTTP;
  end-to-end export/publishing remains unverified.

## API transport and token setup, 2026-10-12

- Merged `feat/gitkeeper-foundation` into `main` without conflicts, after updating local `main` to
  the fetched remote head. Removed the duplicated README App link introduced by the merge and changed
  the installer command/documentation to use `main`.
- Reproduced **Create and verify token** in the open live frontend with its existing HTTP URL and
  unchecked consent: the POST redirects to the generic token-setup rejection. No token was created.
  The former HTTPS-only validation rejected the address before native token creation.
- Implemented HTTP and HTTPS Zabbix endpoints, with HTTPS certificate verification enabled by default
  and a persisted, explicit per-endpoint option to skip it through the official client's `validate_certs`.
  Existing saved credentials and queue requests default to verification. GitHub transport is unchanged.
- Prefilling now probes a derived loopback endpoint without credentials before falling back to the
  configured frontend/current-page URL. Port/path/scheme come from PHP server variables; the probe has
  a two-second timeout, does not follow redirects and requires a matching API version. This is generic
  detection, not evidence that loopback works on the supplied server. An already verified URL wins.
- Added browser field validation, specific safe rejection messages, generation/queue cleanup checks,
  live API-tab state/errors and certificate/URL sharing between token forms. Permission-check failures
  preserve the old verified token and expose a safe role-permission message without raw API responses.
- Passed Ruff, 40 Python cases (39 passed, one POSIX skip on Windows), PHP contracts and all PHP lint,
  Node polling/loopback regressions, and diff whitespace checks. A real official-client HTTP request
  against an isolated loopback JSON-RPC fixture verified token authentication and worker persistence.
  PHP fixtures cover native token creation/generation/queue handoff, cleanup failures and local probe
  success/fallback. These changes have not been deployed to the supplied server; its loopback endpoint,
  real token creation/verification and full export/publishing remain unverified.

## Synchronization setup guidance, 2026-10-12

- Read-only inspection of the installed public status showed GitHub Ready and a saved private
  destination, with no verified Zabbix API token or saved categories/interval. No snapshot was queued.
- Added an explicit synchronization setup state, all missing prerequisites beside Sync now, native
  tab links and submission/queue feedback. Worker Ready is no longer presented as overall setup readiness.
- Tests and visual verification are left to the user for this change, as requested.

## Persistent API token verification result, 2026-10-12

- Read-only installed status showed `set_zabbix` completed with no error, `zabbix_configured: true`
  and the verified endpoint `http://127.0.0.1/zabbix/api_jsonrpc.php`. No request was pending.
- Added a persistent API result panel, live submission/queued/running/verified/failed states and a
  Configuration / Save & Sync next step. The redirect carries only the request ID; queued status is
  derived from its filename without reading credentials. The worker retains the verification result
  and its safe error separately from other operations. Existing verified credentials show success.
- Tests and visual verification are left to the user for this change, as requested.

## Save & Sync progress visibility, 2026-10-12

- Read-only inspection of installed status and saved settings showed a successful save with all five
  categories, a 15-minute interval and the selected private repository. The worker was exporting
  templates after exporting actions, hosts and media types; no error or queued request was present.
- The synchronization setup label and missing-steps list were only rendered on page load. Polling
  deferred a full reload during form edits/focus and did not update those fields, allowing an obsolete
  incomplete-setup message to remain visible while export was running.
- Added shared status computation for initial rendering and polling, request-ID correlation for
  queued saves/snapshots, distinct queued/validating/exporting/completed/failed messages and live
  missing-step links. Snapshot buttons are disabled while that work is pending or in progress.
- Tests and visual verification are left to the user, as requested. No new snapshot was triggered
  and the ongoing worker was not interrupted for diagnosis or deployment.

## Immediate UI request activation, 2026-10-12

- Implemented native systemd path activation for finalized queue JSON files. Cron starts the same
  oneshot worker service, so UI requests and scheduled checks share service serialization and the
  existing worker lock. Requests arriving during an export reactivate the service after it exits.
- Unit files are distributed with the project. Installation requires running systemd, checks for
  foreign/symlinked unit targets and automatically installs/enables the watcher. Upgrades pause the
  watcher while updating under the worker lock. Uninstall disables/removes the managed units while
  preserving credentials and pending requests. No PHP shell execution or service permissions were added.
- Updated the existing installer fixture to check unit installation and isolate its queue-permission
  check from automatic execution. Tests, trigger verification and visual checks are left to the user.
  No synthetic request or snapshot was submitted for verification.

## Native host-prototype group links, 2026-10-12

- Installed status identified a templates-category policy rejection on Zabbix 7.4.15, after actions,
  hosts and media types completed export. A read-only schema inspection of 364 native template
  responses took about 20 seconds and identified 51 occurrences of `group` inside prototype
  `group_links`. It inspected structural field names only; no raw values or credentials were printed,
  no snapshot was written or pushed, and no worker request was queued.
- The policy incorrectly treated group links as direct name references. Official 7.0 and 7.4 validators
  define `group_links[]/group/name`. Added the explicit nested context to the shared policy, with no
  server-specific exception. Unknown fields continue to stop publication.
- Policy errors now include category, reviewed series and the containing schema path, without
  reflecting unknown keys or values. Added a regression for host/template prototype group links in
  both series, macro redaction and safe rejection without a success manifest.
- Tests and the next actual synchronization are left to the user, as requested. This diagnosis and
  correction do not establish a completed end-to-end snapshot.

## Scheduling syntax and contextual macros, 2026-10-12

- Installed status showed a templates failure with `Unexpected control or scheduling value in export`.
  Read-only structural inspection of 364 native template responses identified semicolons in 49
  item/prototype delays, plus quotes in 29 delay/timeout values. A metadata-only follow-up confirmed
  all 29 quoted values reference contextual user macros. No values, credentials or template names
  were printed; no worker request, snapshot or Git push was triggered by these inspections.
- The generic scalar character filter rejected Zabbix's documented custom intervals and quoted macro
  contexts. Added an explicit schedule rule for item/discovery/prototype delays and user-media periods.
  Contextual macros in scalar/schedule values are omitted with an exclusion path, consistently for
  quoted and unquoted contexts. Other control fields retain the stricter punctuation restriction.
- Scalar syntax/type failures now use the safe policy error, carrying category, reviewed series and
  the schema field path without reflecting its value. Added fixtures for both target series, custom
  intervals, time periods, contextual macro redaction and rejection without a success manifest.
- Tests and the next actual synchronization remain assigned to the user. The updated policy has not
  yet been verified by a completed live snapshot.

## Long-running snapshot and category progress, 2026-10-12

- Read-only inspection of the user-started synchronization showed an active worker and 239 written
  template-object files, with the latest file just updated. Public status had remained at the templates
  category start because progress was previously reported only at category boundaries.
- That existing synchronization subsequently completed in 374 seconds, with no error and commit
  `855bd2b25e91982f83a2c900be807972c61d5950` for the private destination repository. Public coverage reported
  actions 5, hosts/groups 26, media types 39, templates/groups 376 and users/groups/roles 12. This is
  observation of the user's actual run, not a newly triggered test or full integration certification.
- Added checked/written host/template counts to operational progress, live category cells independent
  of full-page reloads, and a separate GitHub publication phase. Progress counters do not enter the
  deterministic manifest. Scheduled exports identify their operation as sync as well.
- Added Python progress/manifest/privacy/failure fixtures and a frontend polling regression covering
  live counts with a focused form. Tests and visual verification remain assigned to the user; the
  ongoing synchronization was allowed to finish without interruption before deployment.

## Earlier automated checks

Executed successfully on 2026-10-11:

- Local Python lint/compilation, 25 unittest cases (24 passed; POSIX lock test skipped on Windows),
  PHP lint and controller/queue contracts, shell syntax, and wheel/sdist packaging.
- [Linux CI](https://github.com/Capobuf/Zabbix-GitKeeper/actions/runs/38164659648): 25 tests passed
  including the actual separate-process lock, PHP checks, ShellCheck, packaging and the root installer
  fixture. Installer rerun and preserved-state uninstall passed. The CI fixture caught writable package
  entrypoints; the installer now explicitly removes group/other write permissions after packaging.

These are mock/fixture and local-Git results, not live Zabbix/GitHub App verification.

Run the quick local checks from the source root (stop on first Python test failure):

```sh
python -m pip install -e ./worker ruff build
python -m ruff check --config worker/pyproject.toml worker tests
python -m unittest discover -s tests -v -f
php tests/frontend.php
node tests/frontend-poll.cjs
find frontend tests -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n scripts/install.sh scripts/uninstall.sh tests/install-smoke.sh
python -m build worker
```

The unit suite uses synthetic Zabbix/GitHub APIs and **real native Git/local bare repositories**.
It checks configuration/whitelist validation, deterministic version fixtures, semantic list order,
readable configuration/credential preservation, unavailable macro markers, unknown-field rejection,
complete enumeration/native item checks, privacy/access,
OAuth polling/refresh/revocation, safe public errors, scheduling/backoff, one-commit/no-change behavior,
host/template/action diffs, failed-export/push preservation and foreign-file protection.
POSIX process-lock testing is skipped on Windows and runs in Linux CI. PHP tests use controller contract
doubles; they check Super admin gates, native CSRF remaining enabled, explicit bootstrap consent,
input validation, atomic queue publication and immediate Queued state. They do not simulate a certified
native browser session or prove full frontend registration.

CI runs the same fast checks on Linux, packaging, ShellCheck and an installer/uninstaller fixture smoke
test. That smoke test is root-only and restricted to disposable GitHub Actions runners. It verifies
rerun preservation, a single cron entry, filesystem credential/executable isolation, request readability
by the worker and state-preserving uninstall. Its mocked frontend/server markers **are not Zabbix**.
See the PR checks for the executed Linux CI outcome; a configured workflow alone is not a passing test.

## Full live acceptance (not completed)

On a disposable Linux VM for **each** 7.0/7.4 profile to be advertised:

1. Install on the verified frontend path and PHP process identity. Repeat installation; compare cron
   and preserved credentials/checkouts. Enable the module using Zabbix's native scan.
2. Verify menu/page rendering in both themes, Super admin access only, unauthorized POST/CSRF rejection,
   and preservation of typed settings while polling. Confirm PHP cannot read worker tokens or edit code.
3. Use a real App Client ID. Complete Device Flow, install the App, refresh tokens, disconnect/revoke
   authorization and verify a visible Reconnect state. Do not expose token values in captured evidence.
4. Confirm public/no-write repositories are absent/rejected; test private creation with and without
   Repository creation permission and grant access to a newly created repository.
5. Exercise native token creation with consent, existing-token verification, replacement and server-side
   revocation. Verify denied API roles produce errors and a partial-visibility account is rejected.
6. Build expected host/template/item/trigger/LLD/action/media/user/group/role inventories, including
   webhook, script, SNMP and secret macro examples. Compare actual snapshots to expected covered fields;
   verify readable configuration/credential sentinels are present in snapshots, unavailable values are
   identified, and GitKeeper authentication tokens/API values never leak into public status/errors.
7. Save settings, close the browser, observe cron produce one private GitHub commit. Repeat unchanged,
   then modify a tracked field. Verify cadence, object-deletion handling and manifest checksums.
8. Induce incomplete API export, revoked access, network failure, protected branch, remote divergence,
   foreign managed file and concurrent worker execution. Verify no partial snapshot is published and
   `last_success`/last valid snapshot are preserved. Observe bounded retries.
9. Uninstall, checking credentials/checkouts/data remain and no core files were changed. Reinstall and
   confirm preserved configuration. Document distribution, package paths, PHP version and Zabbix patch.

Do not mark iteration acceptance or publish a stable release until this full sequence succeeds on at
least one target version. Full restore/disaster recovery belongs to later milestones and needs its own
successful recovery onto an empty instance; nothing in the current test suite establishes that capability.

## Native UI/UX refactor — 2026-10-12

The earlier four-tab UI and references to a maintainer/shared GitHub App above are historical evidence.
The current procedure uses **Panoramica** and **Impostazioni**, and each installation registers its own
App. No shared Client ID is distributed. Device Flow is enabled manually. The registration link uses
the verified homepage/description, Contents write, webhook, OAuth-installation and availability parameters.
Repository creation write is an explicit optional manual setting: its registration query name has not
been verified, so no guessed URL parameter is emitted.

Source checks completed for this refactor:

- PHP lint for all frontend/test files and `php tests/frontend.php`: passed. Contracts cover Super admin
  permissions, native CSRF, token consent/secrecy, queue validation, setup and synchronization states.
- `node tests/frontend-poll.cjs`: passed against the actual asset with lightweight DOM/status doubles.
  Covers empty/missing-worker setup, API verification, Device Flow pending/expired/denied, authorization
  distinct from installation access, private repository selection, native synthetic selection events,
  unsaved fields, progress/publication, persistent snapshot errors, request correlation, duplicate submits,
  visibility/no overlap, unavailable status and expired sessions. No browser E2E framework was added.
- Ruff and Python compilation: passed. **48 Python tests passed on Linux**, including the real process
  lock. Windows ran the same suite with the POSIX-only lock test skipped. New regressions cover claimed
  request correlation, failed synchronization surviving another successful operation/restart, publication
  preceding success, unchanged commit retention, and scheduled snapshots not reusing old manual IDs.
- Worker sdist/wheel build and shell syntax checks: passed. Installer smoke/ShellCheck were not rerun in
  this session; the unchanged CI still includes them. The installer smoke is for disposable CI runners,
  never the existing live server. Python tests can be rerun with `python -m unittest discover -s tests -v -f`
  to stop on the first failure.

Live observations on the existing **Zabbix 7.4.15** instance:

- Updated the native module and only the worker's public state/correlation code. Existing credentials,
  repository, categories and interval were retained. The pre-update code was backed up on the server.
  The unrelated local readable-credentials exporter/policy edits were not deployed for this UI test;
  live results therefore still use the earlier redaction policy.
- Both tabs rendered, native fieldsets expanded/collapsed, and no JavaScript warnings/errors were
  reported. An existing long export and a new UI-triggered export advanced host/template counts through
  polling without automatic navigation. The prior successful timestamp/commit stayed visible while busy.
- A native **Sincronizza ora** POST was accepted, the service processed the correlated request and the UI
  showed publication success. Commit `d07012bf51653fb2994f0123e08d66012b0e4d9a` was also verified on GitHub.
  The theme test overlapped that export and its temporary high-contrast preference was captured in the
  user configuration; the original preference was restored and a further snapshot completed at 09:51:53
  CEST. Commit `5ec4bef4a74be79bd21022aa66c8f206ef051e0c` was verified on GitHub to restore `theme: default`.
- Client ID text, a changed native interval and an unchecked category survived subsequent status updates
  without being submitted. Test edits were discarded afterward. The browser was returned to Panoramica.
- A subsequent native **Verifica connessione** POST retained the Impostazioni tab and showed its own
  completed result. The connection section remained reopenable, its Client ID link expanded the native
  advanced fieldset, and the last snapshot timestamp/commit remained unchanged.
- Native blue and high-contrast-light stylesheets were selected successfully, then the original system
  theme was restored. Dark Reader overrides the visible colors in this browser, so this does **not** prove
  the original light/high-contrast appearance. The viewport override did not change the observed 2560px
  layout; it was reset, and narrow-screen visual acceptance remains pending.

Not exercised live in this session: a clean 7.0 installation, fresh App registration/Device Flow and token
creation, permission-denied/new-repository cases, induced export/push failures, and installer rerun/uninstall.
Opening GitHub App registration reached GitHub's access-verification screen; no new App or permission was
created. Fixture checks are evidence for their contracts, not certification of those complete live flows.
The full acceptance sequence above, inventory comparisons and readable-credentials live acceptance remain
required before release claims. Restore and complete disaster recovery remain unimplemented.

### Default cadence update — 2026-10-12

The default for new configurations is now **once every 3 days** (4320 minutes). Both queue validators
accept this interval and the native selector exposes **3 giorni**. Previously saved schedules are retained.
PHP lint/contracts, Node polling checks, Ruff and the Python configuration/queue regression passed.
The frontend and worker interval validator were updated on the existing instance, with deployed file
hashes checked against source. The earlier 15-minute observations above remain historical evidence.

## Live readable-policy migration — 2026-10-12

The installed worker still had the old redaction policy: its schema-1 snapshot contained
`<omitted: potentially sensitive>` in 428 of 439 JSON files. Updating the frontend alone had not
changed that policy. Installed the local worker wheel through the existing virtual environment's pip,
with the worker lock held, and verified every packaged worker file against the installed bytes.
The previous package and CLI were backed up under
`/var/backups/zabbix-gitkeeper/readable-policy-20261012-_min0fj9/`. Private JSON state was unchanged
during installation; the existing repository, categories, credentials and 15-minute interval were retained.

Queued one native `sync` request (`aec4eb3b226b40a6b90832720c340362`) and ran it through the existing
systemd service. All selected categories completed: actions 5, hosts/groups 26, media types 39,
templates/groups 376 (364 templates) and users/groups/roles 12. Publication completed at 10:12:56 CEST:
commit `444deec9d1a89d14de0a090b20e32425302fb545` in the private destination repository.

Verification after publication:

- GitHub `main` points to the reported commit. The GitHub commit tree and manifest match the local
  published snapshot; all 438 data-file checksums passed.
- The schema-2 snapshot has 439 JSON files and **zero** files containing the old omission marker.
  The documented `<not-readable: secret macro>` marker remains in 115 files.
- The previous successful commit `5ec4bef4a74be79bd21022aa66c8f206ef051e0c` is the direct parent;
  only `gitkeeper/` files changed. A normal commit/push updated already tracked files, without rewriting
  history or force-pushing. Historic snapshots retain their old values and omission markers.
- Local worker build, Ruff with `worker/pyproject.toml`, and 48 Python tests passed (the POSIX-only
  lock test was skipped on Windows). No new synchronization mechanism was required.

This verifies the live migration and complete selected-collector publication on Zabbix 7.4.15.
It does not complete the field-by-field inventory or fresh-installation acceptance sequence above.

## Supplied branding — 2026-10-12

The three original SVG logos are organized in `branding/` as `logo-black.svg`, `logo-white.svg` and
`logo-text.svg`. Their contents were preserved when moving/renaming them. The module's `assets/img/`
copies keep the supplied artwork with a tighter SVG viewport, removing unused canvas space.
README uses the logo with text; the GitKeeper page uses native `CImg` and `CView::getAssetsPath()`.
Native `getUserTheme()` selects the white variant for `dark-theme`/`hc-dark`, otherwise the black variant.
The existing installer already copies these assets; no installer or worker changes were needed.

PHP view lint and the frontend controller/queue contracts passed. The updated view, stylesheet and
three SVG assets were deployed to the existing 7.4.15 module, with installed bytes verified against
the package. Previous frontend files were backed up under
`/var/backups/zabbix-gitkeeper/branding-20261012-ntipcp16/`.
A separate browser tab verified that the black logo loads from the module-relative URL and renders
at 112 by approximately 33 pixels. The original browser tab and its form contents were preserved.
No theme preference was changed for this check; live selection of the white variant was not exercised.

## Custom synchronization intervals — 2026-10-12

The existing presets and 3-day default remain available. **Personalizzato** exposes native number
inputs for days, hours (0–23) and minutes (0–59), with a minimum total of one minute. PHP converts
the components to total minutes; queue and worker validate positive integers up to the native int32
limit. The existing settings/queue schema and completion-based scheduler are unchanged.
Overview displays the duration in days, hours and minutes. Polling preserves custom duration drafts.

PHP lint and controller/queue tests, JavaScript polling tests, Ruff and all 48 Python tests passed
locally (the POSIX lock test was skipped on Windows). On the existing Linux instance, the POSIX lock,
interval validation and custom completion-based scheduling tests also passed. The worker wheel and
four frontend files were deployed and their installed bytes verified; private JSON state was unchanged
during deployment. Previous files were backed up under
`/var/backups/zabbix-gitkeeper/interval-20261012-90_0n_c7/`.

A separate browser tab verified the preset/custom switch and native fields on Zabbix 7.4.15.
A zero duration produced a validation error; a draft of 1 day, 2 hours and 30 minutes was valid.
No settings were submitted or synchronization queued during this browser check. Custom save handoff
and scheduling were exercised by isolated controller and worker tests, not by changing the live schedule.

## Smart Template Scope — 2026-10-12 (local implementation, no live deployment)

Starting HEAD was `bb808aa28acea2e3ed2a738217ddc30862f6acfe`, matching the supplied analysis.
The working tree was clean. This change implements `all`/`used`, manual selections, deterministic
scope metadata in schema 2, bulk dependency resolution and non-destructive queued previews.
It preserves existing settings without a scope as `all` and preserves legacy `all` snapshot bytes.
During the initial local implementation, no production installation, settings, monitoring configuration
or backup repository was modified and no live template totals were measured. The subsequent authorized
server update and live measurements are recorded below.

Local evidence:

Checks ran on Windows with Python 3.12.10, PHP 8.3.10 and the available native Git/Node tools.
The workflow's Linux/Python 3.10 environment was not exercised locally.

- **62 Python tests: 61 passed, one POSIX lock test skipped on Windows.** The same existing fixture
  suite now covers direct links from disabled/discovered hosts, parent chains/cycles, unused children,
  pertinent versus unrelated prototypes, discovery action link/unlink operations including disabled
  actions, actions outside selected export categories, manual roots/dependencies/prototypes, verified
  missing manual IDs, malformed and partial inventories/relations, changes during calculation/export,
  empty verified selections, native verification and deterministic legacy/selective manifests.
- Real local temporary bare Git repositories verify `all → used → all`, no commit for an identical
  selective snapshot, removal only from the current tree, access to previous commits and preservation
  of the valid destination after export/push failure. These are local fixtures, not GitHub acceptance.
- `php tests/frontend.php` passes controller/queue validation, native CSRF retention and Super admin
  contracts, preview handoff/JSON layout/correlation, missing versus confirmed scope transitions and
  normalized saved IDs. PHP lint passes for every frontend/test PHP file.
- `node tests/frontend-poll.cjs` passes Queued/Running/Completed/Failed preview display, request
  correlation, CSRF-bearing POSTs, duplicate protection, current unsaved scope choices, preservation
  of manual/interval edits and save confirmation, alongside the prior sync/polling regressions.
- Ruff (worker, tests and live diagnostic), compileall, native Bash syntax checks, ShellCheck 0.11.0
  and worker sdist/wheel build pass. No new runtime or frontend dependency was added.

Both 7.0 and 7.4 official sources were reviewed for `selectParentTemplates` direction, prototype
`parentHost`/`templates`, action operation types/selectors and `CMultiSelect` popup parameters.
The [coverage references](coverage.md#template-scope-coverage-70-and-74) identify those sources.
Versioned fixture cases exercise both series; this does **not** certify a live 7.0/7.4 server or
native selector rendering. Prior live evidence above predates Smart Template Scope.

At the end of local verification, remaining checks were Linux POSIX lock and disposable root installer smoke test; live preview and
native multiselect/save/queue behavior on authorized test instances in each target series. WSL cannot
start in this workspace because its distro disk is missing, and the Docker daemon is unavailable.
The complete Linux CI workflow and installer smoke test were therefore not run here. The existing
smoke script is restricted to disposable GitHub Actions runners and must not run on production.

To validate scope in read-only mode against an authorized test instance, substitute its actual
endpoint and Super admin username. This prompts for an ephemeral login, makes no native exports,
writes no settings and performs no Git operation:

```sh
python scripts/check-live-export.py --url https://TEST-ZABBIX/api_jsonrpc.php --user TEST-ADMIN --template-mode used --preview-scope
```

Use `--include-templateid ID` repeatedly to check manual roots. For subsequent native checks, omit
`--preview-scope` and use `--categories templates --template-mode used`: the temporary snapshot is
deleted and no Git push is performed. The diagnostic stops on the first error.

On a Linux validation environment with prerequisites installed, the non-root checks stop at the
first failure:

```sh
set -e
python -m ruff check --config worker/pyproject.toml worker tests scripts/check-live-export.py
python -m compileall -q worker
python -m unittest discover -s tests -v -f
while IFS= read -r -d '' file; do php -l "$file"; done < <(find frontend tests -name '*.php' -print0)
php tests/frontend.php
node tests/frontend-poll.cjs
bash -n scripts/install.sh scripts/uninstall.sh tests/install-smoke.sh
shellcheck scripts/install.sh scripts/uninstall.sh tests/install-smoke.sh
python -m build worker
```

Run the existing root installer smoke step only in a disposable GitHub Actions runner as configured
in `.github/workflows/checks.yml`. API reads cannot provide transactional consistency: changes that
revert between verification reads, and changes after the last read, remain an explicit limitation.
Native prototype payload coverage and readable credentials keep the existing documented policy.

### Authorized server update and live scope checks — 2026-10-12

Following explicit authorization to update and test `super@172.16.100.7`, deployed the current local
worker and frontend through `scripts/install.sh`, using the verified frontend root
`/usr/share/zabbix/ui` and Apache process user `www-data`. The installer acquired the existing worker
lock, retained `zabbix-utils==2.0.4`, and re-enabled the existing systemd queue watcher. Apache's
configuration check passed and the service was reloaded. All **22 installed worker/module source
files** match the staged local bytes. No source commit or remote source-repository push was made.

The previous virtual environment, module and installation configuration/units/cron were copied into
the root-only rollback archive `/opt/gitkeeper-rollback-20261012.lPWI58Y1/code.tar.gz` before the upgrade.
Before/after hashes confirm that `settings.json`, the Zabbix/GitHub credentials and saved App settings
are unchanged; the existing backup checkout HEAD is unchanged. The saved scope remains legacy `all`,
with the existing 3-day interval. Monitoring configuration was not edited and no Git publication ran.

Server checks ran on Linux, Python **3.12.3**, PHP **8.3.6**, Node **18.19.1**, and Zabbix **7.4.15**:

- **All 62 Python tests passed**, including the actual POSIX lock test and temporary local Git
  `all → used → all` transitions. These are regression fixtures run on the server, not live API tests.
- PHP lint for every frontend/test PHP file, `php tests/frontend.php`,
  `node tests/frontend-poll.cjs`, compileall and installer/uninstaller/smoke-script Bash syntax passed.
- Installed-code API previews verified **364 templates**: `all` includes 364/excludes 0; automatic
  `used` includes **14/excludes 350**. Adding the existing unused `Zabbix proxy health` template
  (`10048`) manually includes **15/excludes 349**, with one effective manual inclusion.
  An absent manual ID yielded one missing-manual warning only after the verified inventory read.
- A live temporary `used` export completed all configured categories in **24.18 seconds**, including
  native monitoring checks and final scope rechecks: templates/groups **26** (14 templates plus
  **12 groups**), hosts/groups **26**, actions **6**, media types **40**, users/groups/roles **14**.
  The **93 JSON files**, including the manifest, were deleted with the temporary directory; no
  configuration content or credentials were printed, and no Publisher/GitHub operation was invoked.
- A separate browser tab verified legacy `Tutti` initialization, switching to `Solo utilizzati`,
  real CSRF-bearing preview POSTs, systemd queue processing, correlated polling and the actual counts.
  Native manual autocomplete selected `Zabbix proxy health`; the real preview returned 15 inclusions.
  The template popup opened, but selection through its group-filtered list was not exercised.
  Manual selection and a draft interval of 3 days/2 hours remained intact across another preview.
  The `all → used` confirmation was detected; the test did not submit a save. The automation's click
  timed out at the native dialog and a later dismissal found no open dialog; subsequent state checks
  confirmed unchanged settings and backup HEAD. Reloading the test tab discarded its unsaved drafts.
- Apache, Zabbix server and the GitKeeper path watcher remain active; the worker unit reports
  `Result=success` and `ExecMainStatus=0` after queued previews.

Remaining acceptance: live Zabbix 7.0, the complete fresh-installation/OAuth sequence, a confirmed
scope save/publication against a disposable destination, popup list selection, and the complete CI
matrix/disposable root installer smoke test. No smoke installation was run on this existing server.
