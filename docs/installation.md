# Experimental installation and first snapshot

This implementation targets Linux with Zabbix server and frontend on the same machine.
Zabbix **7.0 and 7.4 are targets, not certified versions**. Frontend and partial read-only collector
checks and the updated UI with an existing GitHub connection have run against Zabbix 7.4.15;
the full fresh installation/OAuth/permission acceptance sequence remains unverified. See [validation](validation.md).
There is no stable release or selected project license. Use a disposable instance for acceptance testing.

## Prerequisites

- Python 3.10+ with `venv`/pip, system Git, frontend-compatible PHP (8.0+), running systemd, cron reading `/etc/cron.d`, and standard Linux account/filesystem tools.
- A working Zabbix HTTP or HTTPS JSON-RPC URL. HTTPS validates certificates by default; the UI can explicitly allow an invalid/self-signed certificate. Use loopback when detected for local HTTP traffic.
- The real frontend root and real PHP-FPM/Apache process user. Do not guess them: inspect the active web/PHP service configuration. The frontend root contains `zabbix.php`, `include/defines.inc.php` and `modules/`.
- Your registered GitHub App and its **actual Client ID**. Configure it in the guided App step in Panoramica after installing the worker. No App, client secret, signing key or PAT is distributed.
- A private destination repository and App/user write access. Never use the public source repository as a backup destination.

## Register the GitHub App

**Register your own GitHub App for each installation.** Follow the [App setup guide](github-app.md). There is no official/shared App or global Client ID. The installer accepts an optional initial Client ID; it can also be saved from the UI. GitHub App is the only supported authentication method.

In GitHub developer settings, create/configure a GitHub App and enable **Device Flow**.
Enable user access-token expiration. Set repository **Metadata: read**, **Contents: read/write**.
For direct repository creation, grant **Repository creation: write** where GitHub requires it;
the UI supports personal and organization destinations and provides a native GitHub creation-page fallback.
The authenticated account must be permitted to create repositories in the chosen organization.
Install the App on the destination account and grant access to the selected repository.
User authorization and installation are separate requirements. A selected-repositories installation
may require explicitly adding a newly created repository before it appears in GitKeeper.

Device tokens are refreshed at the official OAuth endpoint without a client secret.
The worker polls no faster than GitHub's interval (the cron cadence can be slower), observes expiry,
handles pending/slow-down/denial, and requires reconnection after revoked/rejected credentials.
The UI disconnect button removes local tokens; revoke the authorization independently in GitHub settings.

Official references: [Device Flow](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app),
[refresh rules](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/refreshing-user-access-tokens),
[repository creation](https://docs.github.com/en/rest/repos/repos#create-a-repository-for-the-authenticated-user).

## Install from this branch

When the frontend module is already enabled and the worker is unavailable, **GitKeeper > Panoramica**
shows a read-only installation command with a **Copy** button. It uses the actual frontend path and
PHP process user, downloads main into a fresh temporary directory,
and invokes the existing installer. Paste it into a Bash terminal on the Zabbix server; the command
stops on the first error. Configure the Client ID in the guided App step in Panoramica afterward. Install the prerequisites
listed above first, and restart the actual PHP service afterward. If PHP cannot detect its process
identity, use the manual instructions below rather than a guessed user.
The command downloads the published branch; unpushed local edits are not included.

Clone the source on the test Zabbix VM, then run from its root:

```sh
git switch main
sudo bash scripts/install.sh \
  --frontend-root /absolute/path/to/the/real/zabbix/frontend \
  --web-user ACTUAL_PHP_PROCESS_USER
```

Replace all uppercase arguments and the path with verified local values. `--frontend-root` may be
omitted only when package metadata identifies exactly one real frontend. Ambiguous/unknown layouts
fail without selecting a random directory. The installer requires evidence of the local Zabbix server,
rejects unrelated module/account targets and uses one fixed cron filename and two managed systemd units.
The installer enables immediate queue activation automatically; no per-server or frontend launch
setting is needed. Hosts without running systemd fail the prerequisite check before installation.
Rerunning it upgrades code
without overwriting settings, tokens or checkouts; repeat the same arguments. No Zabbix core file is changed.
An optional `--client-id ACTUAL_GITHUB_APP_CLIENT_ID` supplies the initial App configuration.
Existing installer-provided IDs are reused, while a Client ID explicitly saved in the UI takes precedence.
Python and package installation require outbound access to the package index.

Restart the **actual PHP-FPM/Apache service** to load its new `gitkeeper-ui` supplementary group;
service names vary, so the installer does not restart a guessed service.
In **Administration → General → Modules**, scan the directory and enable **Zabbix GitKeeper**.
Log in as a Super admin and open **Administration → GitKeeper**.

Installation locations:

| Location | Purpose |
| --- | --- |
| Detected frontend `modules/gitkeeper/` | Root-owned PHP module; never writable by PHP |
| `/opt/zabbix-gitkeeper/venv/` | Root-owned Python worker and pinned `zabbix-utils` |
| `/etc/zabbix-gitkeeper/worker.json` | Client ID and installation paths; root/worker readable |
| `/var/lib/zabbix-gitkeeper/private/github-app.json` | App Client ID saved from the UI; worker-owned and preserved on upgrades |
| `/var/lib/zabbix-gitkeeper/private/` | Worker-only tokens, settings, Git checkouts, export staging and lock |
| `/var/lib/zabbix-gitkeeper/queue/` | Atomic restricted requests; pending token submissions are unreadable by their PHP owner |
| `/var/lib/zabbix-gitkeeper/public/status.json` | Worker-written, frontend-readable secret-free status |
| `/var/log/zabbix-gitkeeper/cron.log` | Preserved logs from older direct-cron installations; current worker logs use the system journal |
| `/etc/systemd/system/zabbix-gitkeeper.path` | Starts the worker when a finalized UI request appears |
| `/etc/systemd/system/zabbix-gitkeeper.service` | Worker identity, command and serialization; logs in the system journal |

## Configure and synchronize

The page has two native tabs: **Panoramica** and **Impostazioni**. A new installation sees these
expandable steps in Panoramica, based on persistent worker state rather than a separate wizard store:

0. **Verifica installazione**: the installed worker needs no repeated prerequisite instructions.
   A missing worker shows the installation command where PHP can detect the real installation path
   and process user. Existing installation with unavailable status is distinguished from absence.
1. **Accesso alla configurazione Zabbix**: create a token with explicit consent using native
   `token.create`/`token.generate`, or expand **Usa un token esistente**. The created token has no
   expiry and inherits the administrator's permissions. URL/TLS options are advanced; TLS verification
   defaults to enabled. The detected local URL is used only after an unauthenticated `apiinfo.version`
   probe matches the frontend version; the verified worker URL takes precedence. HTTP transmits tokens
   without encryption. A browser-created token is not configured until the worker verifies it.
   Failure preserves the previously verified token; revoke unused new tokens in Zabbix separately.
2. **Crea e collega la tua GitHub App**: register your own App, save its **Client ID** (not App ID),
   enable Device Flow manually on GitHub, and authorize your account with the displayed code and Copy
   action. Registration and authorization are separate from installing the App on the repository owner.
   Review the [App settings and organization restrictions](github-app.md).
3. **Repository di destinazione**: grant installation access, then **Aggiorna elenco** and confirm a
   private destination and default branch. A single repository is preselected but needs confirmation.
   Direct private creation supports personal/organization owners when permissions permit. If creation
   succeeded without App access, grant access and refresh; do not recreate it. GitHub's native creation
   page is the fallback. Changing destination pauses scheduling until **Salva e sincronizza**.
4. **Cosa sincronizzare**: all available implemented categories are selected initially, with a 3-day
   default. Preset intervals are 5, 15, 30, 60 minutes, 24 hours and 3 days. Choose **Personalizzato**
   to enter **Giorni**, **Ore** (0–23) and **Minuti** (0–59), with a total of at least one minute.
   **Salva e sincronizza** queues
   validation and the first snapshot without waiting in PHP.

Native submission notifications mean **Richiesta inviata**, not completion. The same page shows the
correlated queue/current-operation/latest result, with synchronization tracked independently from
connection checks. Export category results mean **Elaborata**, not published. Wait for **Snapshot
pubblicato** or **Nessuna modifica rilevata**; the existing commit remains linked in the latter case.
Counts update as categories/objects finish; no estimated percentage is displayed.

After activation, Panoramica shows the last synchronization outcome, last successful snapshot, next run
or suspended schedule, repository/branch, frequency and commit. Failure never hides an earlier successful
snapshot. A later successful GitHub check does not erase the last synchronization error. **Sincronizza
ora** lists missing prerequisites when unavailable and is disabled during a queued/active snapshot.

Impostazioni groups **Sincronizzazione**, **Connessioni** and **Avanzate e gestione credenziali**.
Change frequency directly without repeating the guide. Completed setup steps can be reopened.
Custom intervals are stored as total minutes and shown as days, hours and minutes. The interval starts
after the preceding successful snapshot finishes; scheduled runs are checked once per minute.
Removing local tokens or disconnecting requires confirmation and suspends scheduling; GitHub repositories,
Apps and commits remain, and tokens/authorization must be revoked separately on Zabbix/GitHub.

One read-only polling loop runs every 3 seconds while active and every 20 seconds while idle. It avoids
overlapping requests, pauses in hidden tabs and refreshes when returning. It never implicitly refreshes
GitHub repository lists or calls Zabbix APIs. Dynamic status updates preserve unsaved form input and never
reload the page automatically. Native POST submissions still navigate normally and retain CSRF protection.
The worker continues with the browser closed. Persistent state is recovered on return. Missing/invalid
status is temporary unavailability; an old timestamp warns without declaring a long export failed.
Expired frontend sessions are reported as such, without a JSON parsing error.

Snapshots use the repository's current default branch. No branch-selection or branch-creation UI is
implemented. Configure suitable branch protection before testing; a protection rejection is visible and
does not bypass GitHub rules. Only the `gitkeeper/` area is managed; foreign files cause a refusal to delete it.
Unavailable API values cannot produce a readable diff. This is tracking of the documented readable
scope, not a complete backup or directly importable native export.

### Update an existing snapshot to the readable-credentials policy

Install the updated worker before selecting **Sincronizza ora**. Updating only the frontend does not
change the installed export policy. Each synchronization exports every selected category again and
replaces the complete managed `gitkeeper/` snapshot, including files already committed. A normal commit
and push publish the newly readable values; a force push does not run the export policy and is unnecessary.
Wait for **Snapshot pubblicato**, then check that `gitkeeper/manifest.json` has `schema: 2` and inspect
the files at the new commit. Older commits retain `<omitted: potentially sensitive>` in Git history.
Values unavailable from Zabbix retain the documented unreadable marker.


**Replace/revoke tokens:** submit a new existing API token to replace the worker copy, or select
**Rimuovi token locale Zabbix** to stop scheduling and delete the worker copy. Then revoke the old token in
Zabbix's native API token page. Local removal/replacement is deliberately distinct from server-side
revocation. A native token created successfully but later rejected by worker verification may still exist:
revoke unused `GitKeeper ...` tokens in Zabbix. GitHub reconnection replaces local token material;
revocation on GitHub remains available in account settings.

## Diagnose failures and uninstall

The UI reads actual status and category results. Missing status usually indicates cron, permissions or
installation problems. Check PHP's supplementary groups, the queue watcher and the actual cron service.
As root, start a manual tick through the same service:

```sh
sudo systemctl start --no-block zabbix-gitkeeper.service
sudo systemctl status zabbix-gitkeeper.path zabbix-gitkeeper.service
sudo journalctl -u zabbix-gitkeeper.service -n 50 --no-pager
```

Network/rate-limit failures use bounded retries/backoff; rejected permissions, unclassified fields,
incomplete enumeration and Git failures stop scheduling until **Sync now** or **Save & Sync**.
Git remote transport failures/timeouts also use the bounded retry path; protected-branch and ordinary
Git rejections stop without force-pushing.
Refresh failure/revocation shows **Reconnect GitHub**. Git stderr and raw API errors are never exposed.
Inspect the declared coverage/policy to diagnose unsupported fields, without uploading raw exports.

```sh
sudo bash scripts/uninstall.sh
```

Uninstall refuses to run while the worker holds the lock. It disables/removes the queue watcher and
service, then removes cron, worker and the verified
module directory, and preserves credentials, queues, repositories, state, logs and the system account.
Disable/remove the module record in Zabbix and revoke unused tokens/authorizations. No purge option is provided.

Before claiming support, run the [live acceptance checklist](validation.md) on each advertised version.
