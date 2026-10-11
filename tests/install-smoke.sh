#!/usr/bin/env bash
# Run only on a disposable Linux CI runner, never on a production Zabbix machine.
set -euo pipefail
[[ ${GITHUB_ACTIONS:-} == true ]] || { echo 'This root smoke test is restricted to disposable GitHub Actions runners.' >&2; exit 1; }
SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
TEMP_ROOT=$(mktemp -d)
chmod 0755 "$TEMP_ROOT"
trap 'rm -rf -- "$TEMP_ROOT"' EXIT
mkdir -p "$TEMP_ROOT/frontend/include" "$TEMP_ROOT/frontend/modules" "$TEMP_ROOT/bin" /etc/cron.d
printf '%s\n' '<?php define("ZABBIX_VERSION", "7.0.0");' > "$TEMP_ROOT/frontend/include/defines.inc.php"
printf '%s\n' '<?php // Isolated frontend fixture.' > "$TEMP_ROOT/frontend/zabbix.php"
printf '%s\n' '#!/bin/sh' 'exit 0' > "$TEMP_ROOT/bin/zabbix_server"
chmod 0755 "$TEMP_ROOT/bin/zabbix_server"
export PATH="$TEMP_ROOT/bin:$PATH"
ARGS=(--frontend-root "$TEMP_ROOT/frontend" --web-user www-data --client-id fixture-not-a-real-client-id)
bash "$SOURCE/scripts/install.sh" "${ARGS[@]}"
printf '%s\n' '{"sentinel":"preserve-local-settings"}' > /var/lib/zabbix-gitkeeper/private/settings.json
before=$(sha256sum /var/lib/zabbix-gitkeeper/private/settings.json)
cron_before=$(sha256sum /etc/cron.d/zabbix-gitkeeper)
bash "$SOURCE/scripts/install.sh" "${ARGS[@]}"
[[ $(sha256sum /var/lib/zabbix-gitkeeper/private/settings.json) == "$before" ]]
[[ $(sha256sum /etc/cron.d/zabbix-gitkeeper) == "$cron_before" ]]
[[ $(grep -c '^\* \* \* \* \* ' /etc/cron.d/zabbix-gitkeeper) == 1 ]]
[[ $(stat -c '%a' /var/lib/zabbix-gitkeeper/private) == 700 ]]
[[ $(stat -c '%a' /var/lib/zabbix-gitkeeper/public) == 2750 ]]
runuser -u www-data -- test ! -r /var/lib/zabbix-gitkeeper/private/settings.json
runuser -u www-data -- test ! -w /opt/zabbix-gitkeeper/venv/bin/gitkeeper
runuser -u www-data -- php -r '
require $argv[1]; $q=new Modules\GitKeeper\Queue(); $q->submit("sync");
' "$TEMP_ROOT/frontend/modules/gitkeeper/includes/Queue.php"
runuser -u gitkeeper -- python3 -c '
import glob,json,os
p=glob.glob("/var/lib/zabbix-gitkeeper/queue/*.json")[0]
assert json.load(open(p))["operation"]=="sync"
os.unlink(p)
'
bash "$SOURCE/scripts/uninstall.sh"
[[ ! -e /etc/cron.d/zabbix-gitkeeper && ! -e /opt/zabbix-gitkeeper && ! -e $TEMP_ROOT/frontend/modules/gitkeeper ]]
[[ $(sha256sum /var/lib/zabbix-gitkeeper/private/settings.json) == "$before" ]]
echo 'Installer rerun, filesystem isolation and state-preserving uninstall passed (fixture only).'
