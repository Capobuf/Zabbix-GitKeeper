# Experimental installation and first snapshot

This implementation targets Linux with Zabbix server and frontend on the same machine.
Zabbix **7.0 and 7.4 are targets, not certified versions**. Frontend and partial read-only collector
checks have run against Zabbix 7.4.15; the full installation/OAuth/cron/publishing flow is unverified.
There is no stable release or selected project license. Use a disposable instance for acceptance testing.

## Prerequisites

- Python 3.10+ with `venv`/pip, system Git, frontend-compatible PHP (8.0+), cron reading `/etc/cron.d`, and standard Linux account/filesystem tools.
- A working Zabbix HTTPS JSON-RPC URL with a certificate trusted by Python. For a local CA, install it in the system trust store. Certificate validation cannot be disabled through the UI.
- The real frontend root and real PHP-FPM/Apache process user. Do not guess them: inspect the active web/PHP service configuration. The frontend root contains `zabbix.php`, `include/defines.inc.php` and `modules/`.
- Your registered GitHub App and its **actual Client ID**. Configure it in the GitHub tab after installing the worker. No App, client secret, signing key or PAT is distributed.
- A private destination repository and App/user write access. Never use the public source repository as a backup destination.

## Register the GitHub App

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

When the frontend module is already enabled and the worker is unavailable, **GitKeeper > Overview**
shows a read-only installation command with a **Copy** button. It uses the actual frontend path and
PHP process user, downloads the experimental foundation branch into a fresh temporary directory,
and invokes the existing installer. Paste it into a Bash terminal on the Zabbix server; the command
stops on the first error. Configure the Client ID in the GitHub tab afterward. Install the prerequisites
listed above first, and restart the actual PHP service afterward. If PHP cannot detect its process
identity, use the manual instructions below rather than a guessed user.
The command downloads the published branch; unpushed local edits are not included.

Clone the source on the test Zabbix VM, then run from its root:

```sh
git switch feat/gitkeeper-foundation
sudo bash scripts/install.sh \
  --frontend-root /absolute/path/to/the/real/zabbix/frontend \
  --web-user ACTUAL_PHP_PROCESS_USER
```

Replace all uppercase arguments and the path with verified local values. `--frontend-root` may be
omitted only when package metadata identifies exactly one real frontend. Ambiguous/unknown layouts
fail without selecting a random directory. The installer requires evidence of the local Zabbix server,
rejects unrelated module/account targets and uses one fixed cron filename. Rerunning it upgrades code
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
| `/var/log/zabbix-gitkeeper/cron.log` | Fixed-message startup failures in a worker-only directory |

## Configure and synchronize

In **Administration > GitKeeper > GitHub**, enter **GitHub App Client ID** and select **Save Client ID**.
Use the Client ID shown in the App settings, not the numeric App ID. The worker saves it on the next
cron tick and the field then shows the stored value. Device Flow must be enabled; this flow requires
neither a client secret nor a signing private key. For an organization-owned App, open that
organization's Developer settings on GitHub. Changing the Client ID removes the previous local GitHub
authorization and pending device code, clears the repository selection and stops scheduling. Reconnect
and choose an accessible private repository. Saving the same ID keeps the connection and schedule.

1. In **Zabbix API**, review the prefilled `.../api_jsonrpc.php` URL. GitKeeper uses the worker's
   verified endpoint first, then the native Zabbix frontend URL setting, then the current page address.
   Subdirectories and ports are preserved. An HTTP address stays HTTP and displays an HTTPS prerequisite;
   GitKeeper does not guess a working HTTPS endpoint. The URL remains editable, and both token forms
   share the same value. Only the verified endpoint is published in status; the token remains private.
   Supply an existing token, or explicitly check the permission-inheritance consent and select
   **Create and verify token**. Native creation uses the current administrator's session
   to call `token.create`/`token.generate`; cron never uses that browser session.
   The token is non-expiring and inherits that account's permissions. Review/revoke it in the native
   **API tokens** page. This first iteration requires a Super admin token: a Super-admin-only
   `authentication.get` probe prevents a restricted account's partial visibility being treated as complete.
   The role must allow API calls for each selected collector and `configuration.export`.
2. Wait for the next cron tick and check that verification succeeds. Tokens never reappear in the UI.
3. Select **Connect GitHub**, wait for the device code, open **Authorize on GitHub** and authorize your account.
   Return to GitKeeper; the page checks the result automatically. App installation and repository access
   are a separate step on GitHub. The worker runs once per minute, even if the browser is closed.
4. In **GitHub**, refresh repositories to see the accessible private repositories, their write access and default
   branch. Select one and click **Use repository**; the only accessible repository is preselected when there is
   no saved choice. Selection can be saved before Zabbix API setup and does not start an export. Changing the
   destination pauses the previous schedule until **Save & Sync**.
   Alternatively, enter the owner (prefilled from the selected repository or connected account) and a name,
   then click **Create and select private repository**. Personal and organization creation uses GitHub's native
   REST endpoints. Creation is always private and initialized; the new repository is selected only after App
   access is verified. If it was created but the App cannot access it, grant installation access and refresh;
   do not retry creation of the same name. The native GitHub creation page remains available as a fallback.
5. Saved settings take precedence. First setup selects all categories supported by the installed worker
   and uses the explicit 15-minute default. Adjust the categories or select 5, 15, 30, 60 minutes or 24 hours. **Save & Sync** queues
   settings validation and the first export. Watch **Queued → Running → Completed**, or the error/partial result.
6. Inspect `gitkeeper/manifest.json` and category JSON files on the repository's default branch.
   Run **Sync now** without a configuration change: the commit SHA should stay the same.
   Change a tracked non-secret setting (for example an item interval or action status) and repeat:
   one commit should contain the corresponding diff.

Snapshots use the repository's current default branch. No branch-selection or branch-creation UI is
implemented. Configure suitable branch protection before testing; a protection rejection is visible and
does not bypass GitHub rules. Only the `gitkeeper/` area is managed; foreign files cause a refusal to delete it.
Secret/payload-only changes intentionally produce no diff. This is tracking of the documented readable
scope, not a complete backup or directly importable native export.

The GitHub tab separates App configuration, account authorization and repository selection into three
numbered steps. **Refresh repositories** is beside the destination list; **Connect GitHub** is hidden
when connected or awaiting authorization. Empty-list guidance explains App installation/access without
suggesting that account authorization failed. Overview shows the next incomplete setup step, also shown
in Configuration when synchronization is unavailable. Native notifications describe the requested
operation; a submission notification is not confirmation that the worker completed it.

**Replace/revoke tokens:** submit a new existing API token to replace the worker copy, or select
**Remove stored API token** to stop scheduling and delete the worker copy. Then revoke the old token in
Zabbix's native API token page. Local removal/replacement is deliberately distinct from server-side
revocation. A native token created successfully but later rejected by worker verification may still exist:
revoke unused `GitKeeper ...` tokens in Zabbix. GitHub reconnection replaces local token material;
revocation on GitHub remains available in account settings.

## Diagnose failures and uninstall

The UI reads actual status and category results. Missing status usually indicates cron, permissions or
installation problems. Check PHP's supplementary groups and the actual cron service. As root, a safe
manual tick is:

```sh
sudo -u gitkeeper /opt/zabbix-gitkeeper/venv/bin/gitkeeper --config /etc/zabbix-gitkeeper/worker.json
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

Uninstall refuses to run while the worker holds the lock. It removes cron, worker and the verified
module directory, and preserves credentials, queues, repositories, state, logs and the system account.
Disable/remove the module record in Zabbix and revoke unused tokens/authorizations. No purge option is provided.

Before claiming support, run the [live acceptance checklist](validation.md) on each advertised version.
