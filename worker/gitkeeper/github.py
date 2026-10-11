"""GitHub App device flow and user-token repository access."""

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from .storage import Error, Transient, atomic_json, read_json


class Reconnect(Error):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request(url: str, method: str = "GET", data=None, token: str | None = None):
    headers = {"Accept": "application/json", "User-Agent": "Zabbix-GitKeeper/0.1",
               "X-GitHub-Api-Version": "2022-11-28"}
    body = None
    if data is not None:
        if url.startswith("https://github.com/"):
            body = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            body = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = "Bearer " + token
    try:
        req = urllib.request.Request(url, data=body, method=method, headers=headers)
        with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as response:
            raw = response.read(10_000_001)
        if len(raw) > 10_000_000:
            raise Error("GitHub response exceeds safety limit.")
        return json.loads(raw)
    except urllib.error.HTTPError as exc:
        if exc.code == 401:
            raise Reconnect("GitHub authorization expired or revoked. Reconnect GitHub.") from None
        if exc.code == 429 or exc.code >= 500:
            raise Transient("GitHub temporarily unavailable or rate limited; retry later.") from None
        raise Error(f"GitHub rejected the request (HTTP {exc.code}). Check App access and permissions.") from None
    except (urllib.error.URLError, TimeoutError, OSError):
        raise Transient("Cannot reach GitHub; check connectivity and certificate trust.") from None
    except (ValueError, TypeError):
        raise Error("Invalid GitHub response.") from None


class GitHub:
    def __init__(self, client_id: str, private: Path):
        self.client_id = client_id
        self.path = private / "github.json"
        self.device_path = private / "device.json"

    def connect(self) -> dict:
        data = request("https://github.com/login/device/code", "POST", {"client_id": self.client_id})
        if not all(k in data for k in ("device_code", "user_code", "verification_uri", "expires_in", "interval")):
            raise Error("GitHub App device flow is not configured.")
        if data["verification_uri"] != "https://github.com/login/device":
            raise Error("Unexpected GitHub verification URL.")
        data.update(expires_at=time.time() + int(data["expires_in"]), next_poll=0)
        atomic_json(self.device_path, data)
        return self.device_public(data)

    @staticmethod
    def device_public(data: dict) -> dict:
        return {k: data[k] for k in ("user_code", "verification_uri", "expires_at")}

    def store(self, data: dict) -> None:
        if not isinstance(data.get("access_token"), str) or not data["access_token"].startswith("ghu_"):
            raise Reconnect("GitHub App did not return a user access token. Reconnect GitHub.")
        now = time.time()
        saved = {"access_token": data["access_token"]}
        for key in ("refresh_token",):
            if key in data:
                saved[key] = data[key]
        if "expires_in" in data:
            saved["expires_at"] = now + int(data["expires_in"])
        if "refresh_token_expires_in" in data:
            saved["refresh_expires_at"] = now + int(data["refresh_token_expires_in"])
        atomic_json(self.path, saved)

    def poll(self) -> dict | None:
        data = read_json(self.device_path)
        if not data:
            return None
        now = time.time()
        if now >= data["expires_at"]:
            self.device_path.unlink(missing_ok=True)
            raise Reconnect("GitHub device code expired. Reconnect GitHub.")
        if now < data["next_poll"]:
            return self.device_public(data)
        # Persist the next poll before the network call, including failed attempts.
        data["next_poll"] = now + max(1, int(data["interval"]))
        atomic_json(self.device_path, data)
        result = request("https://github.com/login/oauth/access_token", "POST", {
            "client_id": self.client_id, "device_code": data["device_code"],
            "grant_type": "urn:ietf:params:oauth:grant-type:device_code"
        })
        if result.get("error") == "authorization_pending":
            return self.device_public(data)
        if result.get("error") == "slow_down":
            data["interval"] += 5
            data["next_poll"] = now + data["interval"]
            atomic_json(self.device_path, data)
            return self.device_public(data)
        self.device_path.unlink(missing_ok=True)
        if "error" in result:
            raise Reconnect("GitHub authorization denied or expired. Reconnect GitHub.")
        self.store(result)
        return None

    def disconnect(self) -> None:
        self.path.unlink(missing_ok=True)
        self.device_path.unlink(missing_ok=True)

    def token(self) -> str:
        data = read_json(self.path)
        if not data:
            raise Reconnect("GitHub is disconnected. Reconnect GitHub.")
        if data.get("expires_at", float("inf")) <= time.time() + 300:
            if not data.get("refresh_token") or data.get("refresh_expires_at", float("inf")) <= time.time():
                self.disconnect()
                raise Reconnect("GitHub refresh token expired. Reconnect GitHub.")
            if time.time() < data.get("refresh_retry_at", 0):
                raise Transient("GitHub refresh is waiting for bounded retry backoff.")
            try:
                result = request("https://github.com/login/oauth/access_token", "POST", {
                    "client_id": self.client_id, "grant_type": "refresh_token", "refresh_token": data["refresh_token"]
                })
            except Transient:
                attempts = data.get("refresh_attempts", 0) + 1
                if attempts > 3:
                    self.disconnect()
                    raise Reconnect("GitHub refresh retry limit reached. Reconnect GitHub.") from None
                data.update(refresh_attempts=attempts, refresh_retry_at=time.time() + 60 * 2 ** attempts)
                atomic_json(self.path, data)
                raise
            except Error:
                self.disconnect()
                raise Reconnect("GitHub refresh was rejected. Reconnect GitHub.") from None
            if "error" in result:
                self.disconnect()
                raise Reconnect("GitHub refresh was rejected. Reconnect GitHub.")
            self.store(result)
            data = read_json(self.path)
        return data["access_token"]

    def api(self, path: str, method: str = "GET", data=None):
        try:
            return request("https://api.github.com" + path, method, data, self.token())
        except Reconnect:
            self.disconnect()
            raise

    def pages(self, path: str, key: str) -> list:
        result = []
        for page in range(1, 1001):
            data = self.api(f"{path}?per_page=100&page={page}")
            batch = data.get(key)
            if not isinstance(batch, list):
                raise Error("Incomplete GitHub enumeration.")
            result.extend(batch)
            if len(batch) < 100:
                if len(result) != data.get("total_count", len(result)):
                    raise Error("GitHub repository list changed during enumeration; refresh it.")
                return result
        raise Error("GitHub enumeration exceeds safety limit.")

    def repositories(self) -> list:
        repos = {}
        for installation in self.pages("/user/installations", "installations"):
            if installation.get("suspended_at") or installation.get("permissions", {}).get("contents") != "write":
                continue
            for repo in self.pages(f"/user/installations/{int(installation['id'])}/repositories", "repositories"):
                if repo.get("private") is True and repo.get("permissions", {}).get("push") is True and not repo.get("archived"):
                    repos[repo["id"]] = {k: repo[k] for k in ("id", "full_name", "default_branch", "private")}
        return sorted(repos.values(), key=lambda r: r["full_name"].lower())

    def repository(self, repo_id: int) -> dict:
        repo = next((r for r in self.repositories() if r["id"] == repo_id), None)
        if repo is None:
            raise Error("Selected repository is public, unavailable, or lacks App/user Contents write access.")
        if repo["full_name"].lower() == "capobuf/zabbix-gitkeeper":
            raise Error("The source repository cannot be used as a backup destination.")
        return repo

    def create_repository(self, name: str) -> dict:
        repo = self.api("/user/repos", "POST", {"name": name, "private": True, "auto_init": True})
        try:
            return self.repository(int(repo["id"]))
        except Error:
            raise Error("Private repository created, but App access is missing. Grant access on GitHub and refresh.") from None
