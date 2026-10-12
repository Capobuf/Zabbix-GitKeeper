"""Read-only export acceptance check; keep credentials and snapshots out of source control."""

import argparse
import getpass
import sys
import tempfile
from pathlib import Path

from zabbix_utils import ZabbixAPI

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "worker"))

from gitkeeper.exporter import Exporter  # noqa: E402
from gitkeeper.storage import CATEGORIES, Error  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Zabbix API URL (HTTPS in production)")
    parser.add_argument("--user", required=True, help="Super admin username")
    parser.add_argument("--categories", nargs="+", choices=CATEGORIES, default=list(CATEGORIES))
    args = parser.parse_args()
    api = None
    logged_in = False
    try:
        api = ZabbixAPI(url=args.url, timeout=30)
        api.login(user=args.user, password=getpass.getpass("Zabbix password: "))
        logged_in = True
        # Supplying the authenticated API avoids creating a persistent API token.
        # It does not change the installed worker's HTTPS-only credential validation.
        exporter = Exporter({}, api=api)
        print(f"Zabbix {exporter.version}", flush=True)
        exporter.check_permissions(args.categories)

        def progress(category, state, result):
            count = f" ({result['objects']} objects)" if "objects" in result else ""
            print(f"{category}: {state}{count}", flush=True)

        with tempfile.TemporaryDirectory(prefix="gitkeeper-live-check-") as directory:
            exporter.export(args.categories, Path(directory), progress)
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
