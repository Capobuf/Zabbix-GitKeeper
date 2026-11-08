"""Small, secret-free error boundary for API calls and worker diagnostics."""

import errno
import sys
import traceback
from pathlib import Path

from .storage import Error


class UnsupportedVersion(Error):
    pass


class APIAuthentication(Error):
    pass


class APIPermission(Error):
    pass


class APICompatibility(Error):
    pass


class StructureError(Error):
    pass


class GitError(Error):
    pass


def api_error(exc, method):
    # Inspect only for classification; never return/log the response or request body.
    data = getattr(exc, "data", "")
    if data in ("Not authorized.", "Session terminated, re-login, please.", "API token expired."):
        return APIAuthentication("Zabbix API authentication failed. Verify that the token is enabled, "
                                 "not revoked or expired, and its owner is active.")
    if isinstance(data, str) and data.startswith('No permissions to call "'):
        return APIPermission(f"Zabbix denied {method}. Check the token owner's API role permissions "
                             "(Access to API and method allow/deny list). Whole-instance exports "
                             "require a Super admin token and authentication.get; privileges were not changed.")
    if getattr(exc, "code", None) in (-32601, -32602):
        return APICompatibility(f"Zabbix rejected the method or parameters for {method}. "
                                "Check the installed GitKeeper version and Zabbix API compatibility.")
    return Error(f"Zabbix API request failed at {method}. Check API role permissions and server health; "
                 "the response was withheld. See the worker journal for the failure location.")


def failure_message(exc):
    if isinstance(exc, Error):
        return str(exc)
    if isinstance(exc, OSError):
        if exc.errno in (errno.EACCES, errno.EPERM):
            return "Local filesystem access denied. Check GitKeeper file ownership and worker permissions."
        return "Local filesystem operation failed. Check free space, mounts and GitKeeper state directory access."
    return "Unexpected worker failure. See the GitKeeper system journal for the error class and failure location."


def log_failure(exc):
    # Traceback source lines, exception messages, locals and arbitrary paths may contain secrets.
    # Print only code locations in our package, including the original failure after safe wrapping.
    chain = []
    seen = set()
    while exc is not None and id(exc) not in seen:
        seen.add(id(exc))
        frames = [f"{Path(f.filename).name}:{f.name}:{f.lineno}"
                  for f in traceback.extract_tb(exc.__traceback__)
                  if Path(f.filename).parent.name == "gitkeeper"]
        chain.append(type(exc).__name__ + " at " + (" > ".join(frames) or "worker boundary"))
        exc = exc.__cause__ or exc.__context__
    print("GitKeeper failure: " + " <- ".join(chain), file=sys.stderr, flush=True)
