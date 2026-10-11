# Validation evidence and remaining acceptance checks

Local development environment: Windows, Python 3.12, PHP CLI and native Git. No live Zabbix server,
configured GitKeeper GitHub App/Client ID or private integration repository was provided. Docker's
Linux engine was unavailable and the registered WSL distribution could not boot. Neither target
Zabbix version is certified. No customer data or real backup credentials were used.

## Automated checks

Run the quick local checks from the source root (stop on first Python test failure):

```sh
python -m pip install -e ./worker ruff build
python -m ruff check --config worker/pyproject.toml worker tests
python -m unittest discover -s tests -v -f
php tests/frontend.php
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

## Live acceptance (not executed)

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
