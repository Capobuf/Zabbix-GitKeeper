# GitHub App setup for Zabbix GitKeeper

> **Current status:** GitKeeper is experimental. The GitHub App integration exists on the \`feat/gitkeeper-foundation\` branch, but has **not** been validated end-to-end against a live Zabbix installation. This is a configuration guide, not a release certification.

## Recommended approach: one App, any number of GitKeeper installations

**The GitHub App is registered once by the GitKeeper maintainer. It is NOT registered again for every Zabbix server.**

- The maintainer creates a **publicly installable GitHub App** named, for example, *Zabbix GitKeeper* (subject to GitHub name availability), and publishes its **Client ID** and installation URL.
- Each Zabbix administrator installs that same GitHub App on the GitHub account or organization **that owns the backup repository**, grants access to the intended private repositories, and chooses **Connect GitHub** inside Zabbix.
- GitKeeper's built-in **Device Flow** displays a code; the administrator authorizes the App on GitHub. GitKeeper then obtains its own user access token and refresh token for that Zabbix installation.
- The local worker stores those tokens securely and uses them for API calls and Git pushes. No central GitKeeper server, webhook receiver, callback URL, PEM private key, or distributed GitHub App client secret is needed.

**Publicly installable App ≠ public backup repository.** A publicly installable App can be used by different accounts; **backup destinations must remain private**.

We support **GitHub App authentication only**. There is no Personal Access Token, SSH deploy key, or GitHub CLI authentication mode.

### What is created once vs per installation?

| Item | Who does it? | How often? |
| --- | --- | --- |
| Register and maintain the GitHub App | GitKeeper maintainer | **Once for the product** |
| Publish App Client ID and installation URL | GitKeeper maintainer | On initial setup / App changes |
| Install the App on an account or organization | Repository owner / organization admin | Once per GitHub account/org (or when access changes) |
| Select which private repositories the App can access | Repository owner / organization admin | Once per repository, adjustable later |
| Authorize GitKeeper using the code shown in Zabbix | Zabbix administrator | **Per Zabbix installation**, again if authorization is revoked |
| Configure source Zabbix API access and export scope | Zabbix administrator | Per Zabbix installation |
| Refresh expiring GitHub tokens | GitKeeper worker | Automatically after initial authorization |

## Part A — Maintainer: register the shared GitHub App once

These steps are for the **GitKeeper project maintainer**, not for every user who installs GitKeeper.

1. Log in to GitHub with the account that should own the App (for example, the project's maintainer or organization account).
2. Open [GitHub → Settings → Developer settings → GitHub Apps → New GitHub App](https://github.com/settings/apps/new).
3. Set the options below.

| GitHub App setting | Value / guidance |
| --- | --- |
| **GitHub App name** | \`Zabbix GitKeeper\`, if available; GitHub App names must be unique |
| **Description** | \`Version and back up Zabbix configuration to private GitHub repositories.\` |
| **Homepage URL** | \`https://github.com/Capobuf/Zabbix-GitKeeper\` |
| **Callback URL** | Leave blank. **Device Flow does not use it** |
| **Expire user authorization tokens** | **Enabled** (recommended; worker refreshes them) |
| **Request user authorization (OAuth) during installation** | **Disabled** (GitKeeper initiates Device Flow separately) |
| **Enable Device Flow** | **Enabled** — required |
| **Setup URL** | Leave blank; optional for this implementation |
| **Webhook / Active** | **Disabled** — there is no webhook receiver |
| **Where can this GitHub App be installed?** | **Any account** if distributing GitKeeper to other users/organizations |
| **Marketplace listing** | Not needed |

If the App is intended **only for the owner's account**, *Only on this account* is possible, but it prevents other GitHub accounts from installing the shared App. For an open-source product, choose **Any account**. This only controls who may install the App; it does not expose private repository data.

### App permissions

Under **Permissions & events** configure:

| Permission | Level | Why |
| --- | --- | --- |
| **Repository → Metadata** | Read-only (mandatory/default) | Discover installation repositories |
| **Repository → Contents** | Read and write | Read the backup destination and push Git commits |
| **Repository → Repository creation** | Read and write / Write, **optional** | Create a new private personal-account repository from GitKeeper |
| All other permissions | No access, unless specifically justified by a tested feature | Least privilege |
| Event subscriptions | None | Scheduled polling uses no webhooks |

**Important:** enable *Repository creation* only if the in-app **Create private repository** button is required. Otherwise, leave it disabled: users can create a private repository on GitHub and grant the App access. Do **not** request broad *Administration: write* just to create repositories.

The implementation currently supports **personal-account** repository creation through \`POST /user/repos\`, not automated organization repository creation. An organization's approval policies can independently restrict creation/installation. GitHub's repository-creation permission is a **repository permission**; follow the labels displayed in the registration UI.

When App permissions are increased later, existing installations may need to approve the new permissions before functionality becomes available.

4. Click **Create GitHub App**.
5. On the resulting App settings page, copy the **Client ID**. **Client ID is not App ID**. The Client ID is a **public identifier**, not a secret.
6. Record the real App **slug** shown by GitHub. Its installation URL has the form \`https://github.com/apps/APP_SLUG/installations/new\`; replace \`APP_SLUG\` with the actual value. Do not assume the slug until the App has been created.
7. Publish **only** the Client ID and installation URL with GitKeeper's release documentation. **Do not publish** any client secret, signing key or private key. This architecture does not need them.

**Recommended future packaging improvement:** once the project's real GitHub App exists, ship the **public Client ID as a default** in official releases. Then the end user will not need to know or type a Client ID. Keep a \`--client-id\` override for development or forks. This improvement is **not implemented yet**.

### Maintainer verification

- In **GitHub App settings**, verify *Device Flow* is enabled and tokens expire.
- Check the App is installable on another GitHub account if you selected *Any account*.
- Verify the minimum permissions and confirm webhook events are disabled.
- Test a real private repository and an isolated Zabbix instance before claiming production support.
- Do not confuse App registration with App installation: registration creates the shared integration; installation grants access to a particular account's repositories.

## Part B — Zabbix administrator: connect without registering an App

**Prerequisite:** the GitKeeper maintainer has already registered the shared App and provided its genuine Client ID and installation URL.

1. Create or identify a **private** GitHub repository for Zabbix configuration. Do not use this **public source-code repository** as a backup destination.
2. Open the GitHub App's installation link supplied by the maintainer. Choose your personal account or organization.
3. Under **Repository access**, select **Only select repositories** and grant access to the private destination repository. Avoid **All repositories** unless you have a specific operational reason.
4. Approve the App's permissions. Installing the App does not automatically authorize a specific Zabbix installation's user access token.
5. Install GitKeeper on the Linux Zabbix VM with the documented installer. For the current experimental branch, supply the **real shared App Client ID**:

   \`\`\`bash
   sudo bash scripts/install.sh \
     --frontend-root /verified/path/to/zabbix/frontend \
     --web-user ACTUAL_PHP_PROCESS_USER \
     --client-id ACTUAL_GITHUB_APP_CLIENT_ID
   \`\`\`

   The \`--frontend-root\` argument can be omitted only when the installer safely detects exactly one frontend. See [experimental installation guide](installation.md). **This terminal command installs GitKeeper; it is not an alternative GitHub authentication method.** The administrator does not need \`gh auth login\`.
6. In Zabbix open **Administration → General → Modules**, scan the module directory and enable **Zabbix GitKeeper**.
7. Open **Administration → GitKeeper** and configure a suitable **Zabbix API token** separately: GitHub authentication does not grant permission to read Zabbix configuration.
8. Select **Connect GitHub**. GitKeeper shows a short-lived user code and the verified URL **https://github.com/login/device**.
9. Open that GitHub URL, enter the code generated by **your own GitKeeper UI**, inspect the requested App identity and authorize it. Do not approve unexpected codes from messages, email or third-party pages.
10. Return to Zabbix. The worker polls at the GitHub-prescribed interval (it may take an additional cron tick), then lists repositories accessible to **both** the authorized user **and** the installed GitHub App.
11. Select the private repository, configure export categories and schedule, and press **Save & Sync**. Inspect the real job status and first successful commit.

**You do not register a new GitHub App, create a callback URL or obtain a Client Secret for this VM.** Different Zabbix installations authorize the **same App** separately; their stored tokens are local to each installation.

### Creating a destination repository from GitKeeper

If the shared App has the optional **Repository creation** permission and the user account allows creation, choose **Create private repository**. GitKeeper requests creation of a **private**, initialized repository under the authenticated personal account.

If GitHub denies creation or organizational creation is required:

1. Use GitKeeper's **Create on GitHub** link to create a **private** repository in the desired account/organization.
2. On GitHub open **Settings → Applications → Installed GitHub Apps → Configure** for GitKeeper, and grant that new repository access under *Repository access*.
3. Return to GitKeeper and refresh the repository list.

An App-created repository is normally added to its installation automatically according to GitHub documentation; still **verify it appears** and has write access before syncing.

## Token lifecycle and security

- The **Client ID can be public**; it identifies the App. Never treat it as a credential.
- A Device Flow user token begins with \`ghu_\`. The local worker stores access/refresh tokens only in its restricted private storage, never in the Git repository or web page.
- With expiring tokens enabled, GitHub documents an **8-hour access token** and a **6-month refresh token**. Refreshing rotates both; the worker must persist the new pair safely.
- A GitHub App **Device Flow** refresh operation uses the Client ID **without a client secret**.
- Access is the **intersection** of the user's permissions, App permissions and repositories granted to the App installation. Organization approval or SAML requirements may impose additional steps.
- If authorization is revoked, expires without refresh or the App is uninstalled, GitKeeper must show **Reconnect GitHub** or an actionable permissions error. Reconnecting does not require registering another App.
- **Disconnect** in Zabbix removes local credentials; for an effective authorization revocation, also use GitHub's settings for authorized/installed applications.
- Backups contain internal configuration and identities; use a private repository and audit export redaction. A successful Git commit is **not** a complete Zabbix disaster-recovery backup.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| **Device flow disabled** | Enable **Device Flow** in the shared GitHub App settings |
| **Invalid client credentials** | Confirm \`--client-id\` contains the App **Client ID**, not App ID or slug |
| **Authorization pending for a while** | Complete the verification on GitHub; polling follows the API interval and the local cron |
| **No repositories listed** | App **installation** exists on the owning account, selected repo access is granted, and the logged-in GitHub user has write access |
| **Repository created but missing** | Check installed App's **Repository access** and refresh the list |
| **403 when creating a repository** | Check optional **Repository creation** permission, App installation permission approval and user's account policy |
| **Push rejected** | Check repository write access, branch rules/protection, and remote divergence; GitKeeper must not force-push |
| **401 / Reconnect GitHub** | Reauthorize via GitKeeper; access or refresh token might have been revoked/expired |
| **Connected but export fails** | GitHub access is separate from the **Zabbix API token and category permissions** |
| **The public source repo is not selectable** | Expected: backup destinations must be **private** |

## Maintainer decisions / implementation checklist

- [ ] Register the **one official shared App** and publish its actual Client ID and installation URL.
- [ ] Validate user Device Flow, token refresh and private repo read/write in a live integration test.
- [ ] Validate personal repo creation with/without the optional Repository creation permission.
- [ ] Make official-release installer default to the shared **public Client ID**, retaining an override for forks.
- [ ] Preserve safe credential storage and clear connection/permission errors in the Zabbix UI.
- [ ] Advertise compatibility only after real tests against the claimed Zabbix versions.

## Official GitHub references

- [Registering a GitHub App](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app)
- [Public versus private GitHub Apps](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/making-a-github-app-public-or-private)
- [Device Flow for GitHub Apps](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app)
- [Refreshing GitHub App user tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/refreshing-user-access-tokens)
- [Installing a GitHub App](https://docs.github.com/en/apps/using-github-apps/installing-your-own-github-app)
- [Managing access to installed apps](https://docs.github.com/en/apps/using-github-apps/reviewing-and-modifying-installed-github-apps)
- [Permissions required for GitHub Apps](https://docs.github.com/en/rest/authentication/permissions-required-for-github-apps)
- [GitHub App best practices](https://docs.github.com/en/apps/creating-github-apps/about-creating-github-apps/best-practices-for-creating-a-github-app)
