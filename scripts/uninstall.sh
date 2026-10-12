#!/usr/bin/env bash
set -euo pipefail
umask 077
die() { printf 'GitKeeper: %s\n' "$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || die 'Run as root.'
[[ $(uname -s) == Linux ]] || die 'Only Linux installations are supported.'
(($# == 0)) || die 'No destructive purge option is provided.'
[[ -f /etc/zabbix-gitkeeper/worker.json ]] || die 'Installation record missing; no files removed.'
MODULE=$(python3 - <<'PY'
import json,pathlib
c=json.load(open('/etc/zabbix-gitkeeper/worker.json'))
p=pathlib.Path(c['module_dir'])
assert p.is_absolute() and p.name=='gitkeeper' and p.parent.name=='modules' and not p.is_symlink()
assert str(p.resolve())==str(p)
if p.exists():
    assert json.load(open(p/'manifest.json'))['id']=='capobuf_gitkeeper'
print(p)
PY
) || die 'Cannot validate module path; no files removed.'
for path in /opt/zabbix-gitkeeper /var/lib/zabbix-gitkeeper/private /etc/cron.d/zabbix-gitkeeper; do
    [[ ! -L $path ]] || die "Refusing symlinked path: $path"
done
for unit in zabbix-gitkeeper.service zabbix-gitkeeper.path; do
    target=/etc/systemd/system/$unit
    [[ ! -L $target ]] || die "Refusing symlinked unit: $target"
    if [[ -e $target ]]; then
        IFS= read -r marker < "$target" || die "Cannot read unit: $target"
        [[ $marker == '# Managed by Zabbix GitKeeper.' ]] || die "Refusing foreign unit: $target"
    fi
done
exec 9>/var/lib/zabbix-gitkeeper/private/worker.lock
flock -n 9 || die 'Worker is running. Retry after it finishes.'
# Stop activation while holding the worker lock, without terminating an export.
if command -v systemctl >/dev/null && [[ -d /run/systemd/system && -f /etc/systemd/system/zabbix-gitkeeper.path ]]; then
    systemctl disable --now zabbix-gitkeeper.path
    systemctl stop zabbix-gitkeeper.service
fi
rm -f -- /etc/cron.d/zabbix-gitkeeper
rm -f -- /etc/systemd/system/zabbix-gitkeeper.path /etc/systemd/system/zabbix-gitkeeper.service
if command -v systemctl >/dev/null && [[ -d /run/systemd/system ]]; then
    systemctl daemon-reload
fi
[[ ! -d $MODULE ]] || rm -rf -- "$MODULE"
[[ ! -d /opt/zabbix-gitkeeper ]] || rm -rf -- /opt/zabbix-gitkeeper
printf '%s\n' 'Worker, cron and module removed. Disable/remove the module record in the Zabbix Modules page.' \
    'Credentials, queued requests, Git repositories, state, logs and the system identity were preserved.' \
    'Revoke unused API tokens in Zabbix and the GitHub App authorization in GitHub settings.'
