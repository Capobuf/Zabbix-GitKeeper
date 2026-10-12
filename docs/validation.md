# Validation evidence and remaining acceptance checks

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
sentinel redaction, unknown-field rejection, complete enumeration/native item checks, privacy/access,
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
   verify omissions and that test secret sentinels never reach tracked files/public status/errors.
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
