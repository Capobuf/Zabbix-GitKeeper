# Configure your own GitHub App

Each GitKeeper installation registers its **own GitHub App**. There is no official/shared App or
project-wide Client ID. GitHub App Device Flow is the only supported authentication method.
GitKeeper requires neither a Client Secret nor a signing private key.

## Register the App

In **GitKeeper > Panoramica**, follow **Crea e collega la tua GitHub App**. The **Crea la tua GitHub App**
link opens GitHub registration with the documented URL parameters for the project homepage,
description, `contents=write`, `webhook_active=false`, `request_oauth_on_install=false`, and `public=false`.
Choose a unique App name yourself. Only select the optional creation checkbox if you want GitKeeper
to create the repository; it shows the instruction to enable **Repository creation: write** manually.
The parameter name for this newer permission has not been verified in the registration URL documentation,
so GitKeeper does not guess a query parameter. It never expands permissions automatically.

For an organization repository, enter the organization name in the registration options or register
directly in its **Settings > Developer settings > GitHub Apps**. The App must be installable on the
repository owner's account. A private App registered on a personal account is not automatically
installable on another organization. Organization approval and your own repository permissions still apply.

Review these GitHub settings before saving:

| Setting | Value |
| --- | --- |
| App name | A unique name chosen by you |
| Homepage | https://github.com/Capobuf/Zabbix-GitKeeper |
| Contents | Read and write |
| Metadata | Read (GitHub-required default) |
| Repository creation | Write only for optional direct creation |
| Device Flow | **Enable manually**; no documented registration URL parameter enables it |
| User access-token expiration | Enabled |
| Request user authorization during installation | Disabled; not needed for this flow |
| Webhooks | Disabled |
| Callback URL | Not required for Device Flow |
| Installation | Owner account only for a private App, or appropriate availability for the actual owner |

Copy the **Client ID**, not the numeric **App ID**, into GitKeeper and select **Salva Client ID**.
The submission only queues the operation: wait for the worker's result on the same page.
Changing the Client ID removes the previous local authorization, clears the selected destination,
and pauses scheduling. Saving the same ID preserves the connection.

## Authorize your account, then install the App

Select **Collega / ricollega GitHub**, copy the displayed code and open
[GitHub Device Flow](https://github.com/login/device). Authorize the account, then return to GitKeeper.
The result updates automatically. Expired/denied codes and revoked authorization require reconnection.
The worker continues independently if the browser is closed; Device Flow polling follows GitHub's interval.

**Account authorization is not App installation.** Install your App from its GitHub settings on the
account that owns the destination repository. Grant access to the chosen private repository.
Use [installed Apps](https://github.com/settings/installations) to review access; for an organization,
use that organization's installation settings. The authorized user also needs push access.

GitKeeper lists only private, unarchived repositories accessible to both the App (Contents write)
and the user (push). An empty list does not prove the App is uninstalled: review installation,
repository selection, organization approval, permissions and user access, then select **Aggiorna elenco**.
Only this explicit refresh sends a repository request; browser status polling does not call GitHub.

## Select or create a repository

Choose the private repository and branch from the native selector and confirm with **Usa repository**.
A single available repository can be preselected, but still needs confirmation. The default branch is used.
Changing destination pauses scheduling until **Salva e sincronizza**.

**Crea nuovo repository** always requests a private, initialized repository. The owner can be your account
or an organization where you are allowed to create repositories. Direct creation needs the optional
Repository creation permission. Otherwise use [GitHub's creation page](https://github.com/new), choose
Private, grant App access, and refresh GitKeeper.

If GitHub created the repository but the App cannot access it, **do not create it again**. Add it to the
App installation's selected repositories and refresh. An existing name, unauthorized owner or rejected
permission appears as an operation error; resolve it or use GitHub's native creation page.
GitKeeper does not request Administration write merely to create a repository.

## Credentials and failures

Local tokens stay outside the frontend and repository. Access/refresh tokens follow GitHub's expiry
and refresh rules. **Disconnetti GitHub** removes local tokens and pauses scheduling; it does not delete
or uninstall the GitHub App, revoke GitHub authorization, or remove repositories/commits.
Revoke authorization separately in [authorized Apps](https://github.com/settings/apps/authorizations).

A completed export is only an intermediate result. Wait for **Snapshot pubblicato** or **Nessuna modifica
rilevata** before treating a snapshot as successful. The last successful snapshot and commit remain visible
after a later failure. No portable restore or complete disaster recovery is implemented.

## Official references

- [Registration URL parameters](https://docs.github.com/en/apps/sharing-github-apps/registering-a-github-app-using-url-parameters)
- [Device Flow and user tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app)
- [Refreshing user tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/refreshing-user-access-tokens)
- [Public and private Apps](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/making-a-github-app-public-or-private)
- [Installing your App](https://docs.github.com/en/apps/using-github-apps/installing-your-own-github-app)
- [GitHub App permissions](https://docs.github.com/en/rest/authentication/permissions-required-for-github-apps)
