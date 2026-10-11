# Experimental installation and first snapshot

This implementation targets Linux with Zabbix server and frontend on the same machine.
Zabbix **7.0 and 7.4 are targets, not certified versions**: neither has been exercised against a live server.
There is no stable release or selected project license. Use a disposable instance for acceptance testing.

## Prerequisites

- Python 3.10+ with `venv`/pip, system Git, frontend-compatible PHP (8.0+), cron reading `/etc/cron.d`, and standard Linux account/filesystem tools.
- A working Zabbix HTTPS JSON-RPC URL with a certificate trusted by Python. For a local CA, install it in the system trust store. Certificate validation cannot be disabled through the UI.
- The real frontend root and real PHP-FPM/Apache process user. Do not guess them: inspect the active web/PHP service configuration. The frontend root contains `zabbix.php`, `include/defines.inc.php` and `modules/`.
- A maintainer-registered GitHub App and its **actual Client ID**. No App, client secret, signing key or PAT is distributed.
- A private destination repository and App/user write access. Never use the public source repository as a backup destination.

## Register the GitHub App

**Register one GitHub App for the project, not one per Zabbix installation.** Follow the [GitHub App setup guide](github-app.md). Official releases can eventually include the public Client ID; the current experimental installer still requires the real ID via `--client-id`. GitHub App is the only supported GitHub authentication method.

In GitHub developer settings, create/configure a GitHub App and enable **Device Flow**.
Enable user access-token expiration. Set repository **Metadata: read**, **Contents: read/write**.
If personal-account repository creation is wanted, grant account **Repository creation: write**;
the UI also provides the GitHub creation-page fallback. Organization creation is not implemented.
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

Clone the source on the test Zabbix VM, then run from its root:

```sh
git switch feat/gitkeeper-foundation
sudo bash scripts/install.sh \
  --frontend-root /absolute/path/to/the/real/zabbix/frontend \
  --web-user ACTUAL_PHP_PROCESS_USER \
  --client-id ACTUAL_GITHUB_APP_CLIENT_ID
```

Replace all uppercase arguments and the path with verified local values. `--frontend-root` may be
omitted only when package metadata identifies exactly one real frontend. Ambiguous/unknown layouts
fail without selecting a random directory. The installer requires evidence of the local Zabbix server,
rejects unrelated module/account targets and uses one fixed cron filename. Rerunning it upgrades code
without overwriting settings, tokens or checkouts; repeat the same arguments. No Zabbix core file is changed.
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
| `/var/lib/zabbix-gitkeeper/private/` | Worker-only tokens, settings, Git checkouts, export staging and lock |
| `/var/lib/zabbix-gitkeeper/queue/` | Atomic restricted requests; pending token submissions are unreadable by their PHP owner |
| `/var/lib/zabbix-gitkeeper/public/status.json` | Worker-written, frontend-readable secret-free status |
| `/var/log/zabbix-gitkeeper/cron.log` | Fixed-message startup failures in a worker-only directory |

## Configure and synchronize

1. In **Zabbix API access**, enter the HTTPS `.../api_jsonrpc.php` URL.
   Supply an existing token, or explicitly check the permission-inheritance consent and select
   **Create and verify native API token**. Native creation uses the current administrator's session
   to call `token.create`/`token.generate`; cron never uses that browser session.
   The token is non-expiring and inherits that account's permissions. Review/revoke it in the native
   **API tokens** page. This first iteration requires a Super admin token: a Super-admin-only
   `authentication.get` probe prevents a restricted account's partial visibility being treated as complete.
   The role must allow API calls for each selected collector and `configuration.export`.
2. Wait for the next cron tick and check that verification succeeds. Tokens never reappear in the UI.
3. Select **Connect GitHub**, wait for the device code, open the verification link and authorize the App.
   Grant installation access as well. The worker runs once per minute, even if the browser is closed.
4. Refresh repositories if needed. Choose an accessible **private** initialized repository, or create
   one with the UI/fallback. Direct creation is always private and initialized; the App must then be able to access it.
5. Select implemented categories and 5, 15 (default), 30, 60 minutes or 24 hours. **Save & Sync** queues
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
