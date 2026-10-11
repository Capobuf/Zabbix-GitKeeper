#!/usr/bin/env bash
set -euo pipefail
umask 077

die() { printf 'GitKeeper: %s\n' "$*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || die 'Run as root.'
[[ $(uname -s) == Linux ]] || die 'Only Linux installations are supported.'
SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
FRONTEND=''
WEB_USER=''
CLIENT_ID=''
while (($#)); do
    case "$1" in
        --frontend-root|--web-user|--client-id)
            (($# >= 2)) || die "Missing value for $1"
            case "$1" in
                --frontend-root) FRONTEND=$2 ;;
                --web-user) WEB_USER=$2 ;;
                --client-id) CLIENT_ID=$2 ;;
            esac
            shift 2 ;;
        *) die "Unknown argument: $1" ;;
    esac
done
for tool in python3 git php getent groupadd useradd usermod install realpath flock; do
    command -v "$tool" >/dev/null || die "Missing requirement: $tool"
done
python3 -c 'import sys, venv; assert sys.version_info >= (3,10)' || die 'Python 3.10+ with venv is required.'
[[ -d /etc/cron.d ]] || die 'A cron implementation reading /etc/cron.d is required.'
command -v zabbix_server >/dev/null || [[ -x /usr/sbin/zabbix_server ]] || [[ -f /etc/zabbix/zabbix_server.conf ]] \
    || die 'No local Zabbix server detected. This iteration requires a shared server/frontend machine.'
[[ -n $WEB_USER ]] || die 'Pass --web-user with the actual PHP-FPM/Apache process user.'
[[ $WEB_USER =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || die 'Invalid web user.'
getent passwd "$WEB_USER" >/dev/null || die 'The web user does not exist.'
[[ $WEB_USER != root && $WEB_USER != gitkeeper ]] || die 'The web process needs a separate unprivileged identity.'

if [[ -z $FRONTEND ]]; then
    CANDIDATES=()
    while IFS= read -r file; do
        [[ $file == */zabbix.php && -f $file ]] || continue
        candidate=$(realpath -- "$(dirname -- "$file")")
        [[ -f $candidate/include/defines.inc.php && -d $candidate/modules ]] || continue
        [[ " ${CANDIDATES[*]-} " == *" $candidate "* ]] || CANDIDATES+=("$candidate")
    done < <(
        if command -v dpkg-query >/dev/null; then
            dpkg-query -L zabbix-frontend-php zabbix-web 2>/dev/null || true
        fi
        if command -v rpm >/dev/null; then
            rpm -ql zabbix-web zabbix-web-deps 2>/dev/null || true
        fi
    )
    ((${#CANDIDATES[@]} == 1)) || die 'Cannot detect a unique frontend. Pass --frontend-root (directory containing zabbix.php and modules).'
    FRONTEND=${CANDIDATES[0]}
fi
FRONTEND=$(realpath -e -- "$FRONTEND")
[[ -f $FRONTEND/zabbix.php && -f $FRONTEND/include/defines.inc.php && -d $FRONTEND/modules ]] \
    || die 'The supplied path is not a Zabbix frontend with a modules directory.'
# PHP, not the shell, expands $argv.
# shellcheck disable=SC2016
VERSION=$(php -r 'include $argv[1]; echo ZABBIX_VERSION;' "$FRONTEND/include/defines.inc.php")
[[ $VERSION == 7.0.* || $VERSION == 7.4.* ]] || die 'This export policy targets Zabbix 7.0 and 7.4 only; real compatibility still requires verification.'
MODULES=$(realpath -e -- "$FRONTEND/modules")
[[ $MODULES == "$FRONTEND/modules" ]] || die 'Symlinked modules paths require a reviewed installation layout.'

for path in /opt/zabbix-gitkeeper /etc/zabbix-gitkeeper /var/lib/zabbix-gitkeeper /var/log/zabbix-gitkeeper "$MODULES/gitkeeper"; do
    [[ ! -L $path ]] || die "Refusing symlinked installation target: $path"
done
if getent passwd gitkeeper >/dev/null; then
    IFS=: read -r _ _ _ _ _ worker_home worker_shell < <(getent passwd gitkeeper)
    [[ $worker_home == /var/lib/zabbix-gitkeeper && $worker_shell == */nologin ]] \
        || die 'An unrelated gitkeeper account exists. Resolve the identity conflict first.'
fi
if [[ -d $MODULES/gitkeeper ]]; then
    python3 -c 'import json,sys; assert json.load(open(sys.argv[1]))["id"] == "capobuf_gitkeeper"' \
        "$MODULES/gitkeeper/manifest.json" || die 'A foreign module occupies the target path.'
fi
if [[ -f /etc/zabbix-gitkeeper/worker.json ]]; then
    [[ -n $CLIENT_ID ]] || CLIENT_ID=$(python3 -c 'import json; print(json.load(open("/etc/zabbix-gitkeeper/worker.json"))["client_id"])')
    PREVIOUS=$(python3 -c 'import json; print(json.load(open("/etc/zabbix-gitkeeper/worker.json"))["module_dir"])')
    [[ $PREVIOUS == "$MODULES/gitkeeper" ]] || die 'Existing module path differs. Uninstall before moving the frontend.'
fi
[[ $CLIENT_ID =~ ^[A-Za-z0-9_.-]{4,128}$ ]] || die 'Pass the real GitHub App --client-id; no default App is distributed.'

getent group gitkeeper >/dev/null || groupadd --system gitkeeper
getent group gitkeeper-ui >/dev/null || groupadd --system gitkeeper-ui
getent passwd gitkeeper >/dev/null || useradd --system --gid gitkeeper --home-dir /var/lib/zabbix-gitkeeper \
    --shell /usr/sbin/nologin gitkeeper
usermod -a -G gitkeeper-ui gitkeeper
usermod -a -G gitkeeper-ui "$WEB_USER"
install -d -o root -g root -m 0755 /opt/zabbix-gitkeeper
install -d -o root -g gitkeeper -m 0750 /etc/zabbix-gitkeeper
install -d -o gitkeeper -g gitkeeper-ui -m 0750 /var/lib/zabbix-gitkeeper
install -d -o gitkeeper -g gitkeeper -m 0700 /var/lib/zabbix-gitkeeper/private
install -d -o gitkeeper -g gitkeeper-ui -m 2770 /var/lib/zabbix-gitkeeper/queue
install -d -o gitkeeper -g gitkeeper-ui -m 2750 /var/lib/zabbix-gitkeeper/public
install -d -o gitkeeper -g gitkeeper -m 0700 /var/log/zabbix-gitkeeper

# The same lock used by cron prevents replacing executables during an export.
exec 9>/var/lib/zabbix-gitkeeper/private/worker.lock
flock -n 9 || die 'Worker is running. Retry installation after it finishes.'
chown gitkeeper:gitkeeper /var/lib/zabbix-gitkeeper/private/worker.lock
chmod 0600 /var/lib/zabbix-gitkeeper/private/worker.lock
if [[ ! -x /opt/zabbix-gitkeeper/venv/bin/python ]]; then
    (umask 022; python3 -m venv /opt/zabbix-gitkeeper/venv)
fi
(umask 022; /opt/zabbix-gitkeeper/venv/bin/python -m pip install --disable-pip-version-check "$SOURCE/worker")
chmod 0755 /opt/zabbix-gitkeeper/venv/lib/python*/site-packages/gitkeeper/askpass.sh
install -d -o root -g root -m 0755 "$MODULES/gitkeeper"
while IFS= read -r -d '' file; do
    relative=${file#"$SOURCE/frontend/gitkeeper/"}
    install -d -o root -g root -m 0755 "$(dirname -- "$MODULES/gitkeeper/$relative")"
    install -o root -g root -m 0644 "$file" "$MODULES/gitkeeper/$relative"
done < <(find "$SOURCE/frontend/gitkeeper" -type f ! -name local.php -print0)

python3 - "$CLIENT_ID" "$MODULES/gitkeeper" "$WEB_USER" <<'PY'
import json, os, pathlib, sys, tempfile
target=pathlib.Path('/etc/zabbix-gitkeeper/worker.json')
data={'client_id':sys.argv[1], 'module_dir':sys.argv[2], 'web_user':sys.argv[3], 'state_dir':'/var/lib/zabbix-gitkeeper'}
fd,name=tempfile.mkstemp(dir=target.parent)
with os.fdopen(fd,'w') as stream:
    json.dump(data,stream,indent=2)
    stream.write('\n')
    stream.flush()
    os.fsync(stream.fileno())
os.replace(name,target)
PY
chown root:gitkeeper /etc/zabbix-gitkeeper/worker.json
chmod 0640 /etc/zabbix-gitkeeper/worker.json
printf '%s\n' '# Managed by Zabbix GitKeeper.' \
    'SHELL=/bin/sh' 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
    '* * * * * gitkeeper /opt/zabbix-gitkeeper/venv/bin/gitkeeper --config /etc/zabbix-gitkeeper/worker.json >> /var/log/zabbix-gitkeeper/cron.log 2>&1' \
    > /etc/cron.d/.zabbix-gitkeeper.tmp
chown root:root /etc/cron.d/.zabbix-gitkeeper.tmp
chmod 0644 /etc/cron.d/.zabbix-gitkeeper.tmp
mv -f /etc/cron.d/.zabbix-gitkeeper.tmp /etc/cron.d/zabbix-gitkeeper
printf '%s\n' 'Installed. Restart PHP-FPM/Apache to pick up group membership.' \
    'In Administration > General > Modules: Scan directory, then enable Zabbix GitKeeper.' \
    'Open Administration > GitKeeper. Configure HTTPS API access, connect/install the GitHub App, select a private repository and Save & Sync.' \
    'Compatibility targets: 7.0 and 7.4; no live Zabbix integration is certified yet.'
