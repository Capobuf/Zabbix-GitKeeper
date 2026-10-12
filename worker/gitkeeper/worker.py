"""One cron tick: bounded queue, OAuth polling and scheduled exports."""

import argparse
import json
import os
import re
import stat
import tempfile
import time
from pathlib import Path

from .exporter import Exporter, Incomplete
from .git import Publisher
from .github import GitHub, Reconnect
from .storage import CATEGORIES, Error, Transient, atomic_json, command, process_lock, read_json


class Worker:
    def __init__(self, config: Path):
        self.install = read_json(config)
        self.root = Path(self.install["state_dir"])
        self.private = self.root / "private"
        self.queue = self.root / "queue"
        self.public = self.root / "public" / "status.json"
        self.status = read_json(self.public, {"state": "Not configured", "github": "Disconnected"})
        self.config_path = self.private / "settings.json"
        self.config = read_json(self.config_path)
        self.app_path = self.private / "github-app.json"
        client_id = read_json(self.app_path).get("client_id", self.install.get("client_id", ""))
        self.github = GitHub(client_id, self.private)

    def flush(self) -> None:
        self.status["updated_at"] = time.time()
        self.status["settings"] = self.config
        self.status["available_categories"] = list(CATEGORIES)
        self.status["github_client_id"] = self.github.client_id
        self.status["zabbix_configured"] = (self.private / "zabbix.json").exists()
        # Publish the verified endpoint for UI prefilling, never the credentials.
        self.status["zabbix_url"] = read_json(self.private / "zabbix.json").get("url")
        atomic_json(self.public, self.status, 0o640)

    def failure(self, exc: Exception) -> None:
        # Exception strings from libraries/API responses are intentionally discarded.
        self.status["error"] = str(exc) if isinstance(exc, Error) else (
            "Operation failed. Check Zabbix API permissions, policy coverage and local filesystem access.")
        self.status["state"] = "Partial / Incomplete" if isinstance(exc, Incomplete) else "Failed"
        if isinstance(exc, Reconnect):
            self.github.disconnect()
            self.status.update(github="Disconnected", account=None, repositories=[], device=None, reconnect=True)
            self.config.pop("sync_pending", None)
            self.config.pop("next_sync", None)
        self.flush()

    def sync(self) -> None:
        if not self.config or not (self.private / "zabbix.json").exists():
            raise Error("Configure a Zabbix API token and save backup settings first.")
        self.status.update(state="Running", last_attempt=time.time(), categories={}, error=None)
        self.flush()

        repo = self.github.repository(self.config["repository_id"])
        exporter = Exporter(read_json(self.private / "zabbix.json"))
        def progress(category, state, result):
            self.status["categories"][category] = {**result, "state": state}
            self.flush()
        with tempfile.TemporaryDirectory(prefix="export-", dir=self.private) as directory:
            stage = Path(directory)
            coverage = exporter.export(self.config["categories"], stage, progress)
            repo = self.github.repository(self.config["repository_id"])
            commit, changed = Publisher(self.private / "repositories", repo, self.github.token()).publish(stage)
        now = time.time()
        self.config.update(next_sync=now + self.config["interval"] * 60, retry_count=0, sync_pending=False)
        self.status.update(state="Completed", last_success=now, last_commit=commit, changed=changed,
                           repository=repo, categories=coverage, error=None, github="Ready", reconnect=False)
        atomic_json(self.config_path, self.config)
        self.flush()

    def select_repository(self, repo: dict) -> None:
        if self.config.get("repository_id") != repo["id"]:
            self.config.update(repository_id=repo["id"], sync_pending=False, retry_count=0)
            for key in ("next_sync", "retry_at"):
                self.config.pop(key, None)
            for key in ("last_commit", "last_success", "last_attempt", "categories", "changed"):
                self.status.pop(key, None)
        self.status.update(repository=repo, state="Ready")

    def execute(self, request: dict) -> None:
        op, params = request["operation"], request["params"]
        self.status["error"] = None
        if op == "set_github_app":
            if params["client_id"] != self.github.client_id:
                # Credentials issued to another App must never be reused.
                self.github.disconnect()
                atomic_json(self.app_path, {"client_id": params["client_id"]})
                self.github = GitHub(params["client_id"], self.private)
                self.config.update(sync_pending=False)
                for key in ("repository_id", "next_sync", "retry_at"):
                    self.config.pop(key, None)
                self.status.update(github="Disconnected", account=None, device=None, repositories=[],
                                   repository=None, reconnect=False, state="Disconnected")
            else:
                atomic_json(self.app_path, {"client_id": params["client_id"]})
        elif op == "connect":
            if not self.github.client_id:
                raise Error("Save your GitHub App Client ID in the GitHub tab before connecting.")
            self.github.disconnect()
            self.status.update(device=self.github.connect(), github="Disconnected", account=None,
                               reconnect=False, state="Disconnected")
        elif op == "disconnect":
            self.github.disconnect()
            self.status.update(github="Disconnected", account=None, device=None, repositories=[], reconnect=False,
                               state="Disconnected")
            self.config.update(sync_pending=False)
            self.config.pop("next_sync", None)
        elif op in ("repositories", "test_github"):
            self.status.update(account=self.github.api("/user")["login"], repositories=self.github.repositories(),
                               github="Ready", reconnect=False, state="Ready")
        elif op == "create_repository":
            repo = self.github.create_repository(params["name"], params.get("owner"))
            self.status["repositories"] = self.github.repositories()
            self.select_repository(repo)
        elif op == "select_repository":
            self.select_repository(self.github.repository(params["repository_id"]))
        elif op == "set_zabbix":
            credentials = {"url": params["url"], "token": params["token"]}
            exporter = Exporter(credentials)
            # Verify the API role here; selected collectors are validated on Save & Sync.
            exporter.check_permissions(self.config.get("categories", list(CATEGORIES)))
            atomic_json(self.private / "zabbix.json", credentials)
            self.status.update(zabbix_version=exporter.version, state="Ready")
        elif op == "clear_zabbix":
            (self.private / "zabbix.json").unlink(missing_ok=True)
            self.config.update(sync_pending=False)
            self.config.pop("next_sync", None)
            self.status["state"] = "Not configured"
        elif op == "save":
            selected = params["settings"]
            repo = self.github.repository(selected["repository_id"])
            if not (self.private / "zabbix.json").exists():
                raise Error("Configure and verify the Zabbix API token first.")
            Exporter(read_json(self.private / "zabbix.json")).check_permissions(selected["categories"])
            self.config = {**selected, "sync_pending": True, "retry_count": 0}
            self.status.update(state="Queued", repository=repo)
        elif op == "sync":
            if not all(self.config.get(key) for key in ("repository_id", "categories", "interval")):
                raise Error("Save backup settings first.")
            self.config.update(sync_pending=True, retry_count=0)
            self.config.pop("retry_at", None)
            self.status["state"] = "Queued"
        atomic_json(self.config_path, self.config)
        self.flush()

    def drain(self) -> None:
        for path in sorted(self.queue.glob("*.json"))[:20]:
            request_id = path.stem
            if not re.fullmatch(r"[a-f0-9]{32}", request_id):
                continue
            claimed = self.private / ("request-" + path.name)
            try:
                os.replace(path, claimed)
                fd = os.open(claimed, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
                with os.fdopen(fd, "rb") as stream:
                    info = os.fstat(stream.fileno())
                    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 65536:
                        raise Error("Unsafe queue request.")
                    data = json.loads(stream.read(65537))
                request = command(data)
                if request["id"] != request_id:
                    raise Error("Request filename mismatch.")
                self.status.update(operation=request["operation"], state="Running")
                self.flush()
                self.execute(request)
            except Exception as exc:
                self.failure(exc)
            finally:
                claimed.unlink(missing_ok=True)
                self.status["last_request"] = request_id
                self.flush()

    def tick(self) -> None:
        with process_lock(self.private / "worker.lock") as acquired:
            if not acquired:
                return
            # Reload only after locking: another process may have just committed state.
            self.config = read_json(self.config_path)
            self.status = read_json(self.public, self.status)
            self.github.client_id = read_json(self.app_path).get("client_id", self.install.get("client_id", ""))
            self.drain()
            try:
                pending = self.github.device_path.exists()
                device = self.github.poll()
                self.status["device"] = device
                if pending and device is None and self.github.path.exists():
                    self.status.update(account=self.github.api("/user")["login"], repositories=self.github.repositories(),
                                       github="Ready", reconnect=False, state="Ready")
                if self.github.path.exists():
                    self.github.token()  # Refresh even if the export cadence is 24 hours.
            except Exception as exc:
                self.failure(exc)
            now = time.time()
            due = self.config.get("sync_pending") or now >= self.config.get("next_sync", float("inf"))
            if due and now >= self.config.get("retry_at", 0) and self.github.path.exists():
                try:
                    self.sync()
                    self.config.pop("retry_at", None)
                except Exception as exc:
                    self.failure(exc)
                    count = self.config.get("retry_count", 0) + 1
                    self.config.update(retry_count=count, sync_pending=False)
                    if isinstance(exc, Transient) and count <= 3:
                        self.config.update(sync_pending=True, retry_at=now + 60 * 2 ** count)
                    else:
                        # No endless retries: an explicit Sync now/Save & Sync is required.
                        self.config.pop("next_sync", None)
                        self.config.pop("retry_at", None)
            atomic_json(self.config_path, self.config)
            self.flush()


def main() -> None:
    parser = argparse.ArgumentParser(description="Process one local GitKeeper cron tick")
    parser.add_argument("--config", type=Path, default=Path("/etc/zabbix-gitkeeper/worker.json"))
    args = parser.parse_args()
    os.umask(0o077)
    try:
        Worker(args.config).tick()
    except Exception:
        parser.exit(1, "GitKeeper could not read its installation/state files; check ownership and permissions.\n")


if __name__ == "__main__":
    main()
