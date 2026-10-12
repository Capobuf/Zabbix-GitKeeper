"""Atomic local state and strict queue protocol."""

import json
import os
import re
import tempfile
from contextlib import contextmanager
from pathlib import Path
from urllib.parse import urlsplit

CATEGORIES = ("hosts", "templates", "actions", "media_types", "users")
MAX_INTERVAL = 2147483647  # Matches the frontend's native int32 input, in minutes.


class Error(Exception):
    """Only fixed, safe messages may cross the worker/UI boundary."""


class Transient(Error):
    pass


def read_json(path: Path, default=None):
    if not path.exists():
        return {} if default is None else default
    return json.loads(path.read_text(encoding="utf-8"))


def atomic_json(path: Path, value, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix=".tmp-", dir=path.parent)
    try:
        os.chmod(name, mode)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            json.dump(value, stream, indent=2, sort_keys=True, ensure_ascii=False, allow_nan=False)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


@contextmanager
def process_lock(path: Path):
    import fcntl

    with path.open("a") as stream:
        try:
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            yield False
            return
        try:
            yield True
        finally:
            fcntl.flock(stream.fileno(), fcntl.LOCK_UN)


def api_url(value: str) -> str:
    if not isinstance(value, str) or len(value) > 2048:
        raise Error("Invalid Zabbix API URL.")
    url = urlsplit(value)
    if (url.scheme not in ("http", "https") or not url.hostname or url.username or url.password
            or url.query or url.fragment or not url.path.endswith("/api_jsonrpc.php")):
        raise Error("Use an HTTP or HTTPS Zabbix URL ending in /api_jsonrpc.php, without credentials.")
    return value


def settings(value: dict) -> dict:
    if not isinstance(value, dict) or set(value) != {"repository_id", "categories", "interval"}:
        raise Error("Invalid settings fields.")
    if type(value["repository_id"]) is not int or value["repository_id"] <= 0:
        raise Error("Select an authorized private repository.")
    if type(value["interval"]) is not int or not 1 <= value["interval"] <= MAX_INTERVAL:
        raise Error("Invalid export interval.")
    cats = value["categories"]
    if (not isinstance(cats, list) or not cats or any(c not in CATEGORIES for c in cats)
            or len(set(cats)) != len(cats)):
        raise Error("Select implemented categories only.")
    return {**value, "categories": sorted(cats)}


def command(value: dict) -> dict:
    operations = {
        "connect": set(), "disconnect": set(), "repositories": set(), "test_github": set(),
        "sync": set(), "clear_zabbix": set(), "save": {"settings"},
        "set_zabbix": {"url", "token"}, "create_repository": {"name"}, "set_github_app": {"client_id"},
        "select_repository": {"repository_id"}
    }
    if not isinstance(value, dict) or set(value) != {"id", "operation", "params"}:
        raise Error("Invalid request envelope.")
    if not isinstance(value["id"], str) or not re.fullmatch(r"[a-f0-9]{32}", value["id"]):
        raise Error("Invalid request ID.")
    op, params = value["operation"], value["params"]
    optional = {"create_repository": {"owner"}, "set_zabbix": {"verify_tls"}}
    if (not isinstance(op, str) or op not in operations or not isinstance(params, dict)
            or (set(params) - optional.get(op, set())) != operations[op]):
        raise Error("Unsupported operation or parameters.")
    if op == "save":
        params["settings"] = settings(params["settings"])
    elif op == "set_zabbix":
        api_url(params["url"])
        if "verify_tls" in params and type(params["verify_tls"]) is not bool:
            raise Error("Invalid TLS verification setting.")
        if not isinstance(params["token"], str) or not re.fullmatch(r"[a-fA-F0-9]{64}", params["token"]):
            raise Error("Invalid Zabbix API token format.")
    elif op == "set_github_app":
        if not isinstance(params["client_id"], str) or not re.fullmatch(r"[A-Za-z0-9_.-]{4,128}", params["client_id"]):
            raise Error("Enter the GitHub App Client ID from its settings page.")
    elif op == "create_repository":
        if not isinstance(params["name"], str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,99}", params["name"]):
            raise Error("Invalid repository name.")
        if "owner" in params and (not isinstance(params["owner"], str)
                or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9-]{0,99}", params["owner"])):
            raise Error("Enter a GitHub account or organization name.")
    elif op == "select_repository":
        if type(params["repository_id"]) is not int or params["repository_id"] <= 0:
            raise Error("Select an authorized private repository.")
    return value
