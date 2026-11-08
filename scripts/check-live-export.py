"""Read-only export acceptance check; keep credentials and snapshots out of source control."""

import argparse
import getpass
import json
import sys
import tempfile
from pathlib import Path

from zabbix_utils import ZabbixAPI

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "worker"))

from gitkeeper.exporter import Exporter, reviewed_series  # noqa: E402
from gitkeeper.storage import CATEGORIES, Error  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--credentials-file", type=Path, help="Worker Zabbix credentials file; token is never printed")
    parser.add_argument("--url", help="Zabbix API URL (HTTPS in production)")
    parser.add_argument("--user", help="Super admin username")
    parser.add_argument("--categories", nargs="+", choices=CATEGORIES, default=list(CATEGORIES))
    parser.add_argument("--template-mode", choices=("all", "used"), default="all")
    parser.add_argument("--include-templateid", action="append", default=[])
    parser.add_argument("--preview-scope", action="store_true", help="Only verify template scope; no native exports")
    args = parser.parse_args()
    if not args.credentials_file and not (args.url and args.user):
        parser.error("Use --credentials-file, or both --url and --user.")
    if args.credentials_file and (args.url or args.user):
        parser.error("Use credentials file or interactive login, not both.")
    api = None
    logged_in = False
    try:
        if args.credentials_file:
            exporter = Exporter(json.loads(args.credentials_file.read_text(encoding="utf-8")))
        else:
            api = ZabbixAPI(url=args.url, timeout=30, skip_version_check=True)
            reviewed_series(str(api.apiinfo.version()))  # Reject unknown series before authentication.
            api.login(user=args.user, password=getpass.getpass("Zabbix password: "))
            logged_in = True
            exporter = Exporter({}, api=api)
        print(f"Zabbix {exporter.version}", flush=True)
        scope = {"mode": args.template_mode, "include_templateids": args.include_templateid}
        if args.preview_scope:
            print(json.dumps(exporter.preview_scope(scope), sort_keys=True), flush=True)
            return 0
        exporter.check_permissions(args.categories, scope)

        def progress(category, state, result):
            count = f" ({result['objects']} objects)" if "objects" in result else ""
            print(f"{category}: {state}{count}", flush=True)

        with tempfile.TemporaryDirectory(prefix="gitkeeper-live-check-") as directory:
            exporter.export(args.categories, Path(directory), progress, scope)
        print("Selected export checks passed. Temporary snapshots removed; no Git push performed.")
        return 0
    except Exception as exc:
        print(str(exc) if isinstance(exc, Error) else "Live check failed; verify API access and collector compatibility.",
              file=sys.stderr)
        return 1
    finally:
        if api is not None and logged_in:
            try:
                api.logout()
            except Exception:
                print("Could not close the temporary API login session.", file=sys.stderr)


if __name__ == "__main__":
    sys.exit(main())
