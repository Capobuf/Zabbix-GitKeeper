"""Publish one complete snapshot with native Git and ephemeral HTTPS auth."""

import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

from .storage import Error, Transient, read_json


class Publisher:
    def __init__(self, root: Path, repo: dict, token: str):
        if repo.get("private") is not True:
            raise Error("Public repositories are forbidden.")
        if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo["full_name"]):
            raise Error("Invalid GitHub repository identity.")
        self.branch = repo["default_branch"]
        self.root = root
        self.checkout = root / str(int(repo["id"]))
        self.url = "https://github.com/" + repo["full_name"] + ".git"
        self.env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        self.env.update(GIT_TERMINAL_PROMPT="0", GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull,
                        GIT_ASKPASS=str(Path(__file__).with_name("askpass.sh")), GITKEEPER_GIT_TOKEN=token,
                        GIT_AUTHOR_NAME="Zabbix GitKeeper", GIT_AUTHOR_EMAIL="gitkeeper@localhost",
                        GIT_COMMITTER_NAME="Zabbix GitKeeper", GIT_COMMITTER_EMAIL="gitkeeper@localhost")

    def git(self, *args: str, cwd: Path | None = None) -> str:
        try:
            result = subprocess.run(["git", "-c", "credential.helper=", "-c", "core.hooksPath=" + os.devnull,
                                     "-c", "commit.gpgsign=false", *args], cwd=cwd, env=self.env,
                                    text=True, encoding="utf-8", errors="replace", capture_output=True, timeout=180)
        except subprocess.TimeoutExpired:
            raise Transient("Git operation timed out; retry with bounded backoff.") from None
        except OSError:
            raise Error("Git failed or timed out. Check connectivity and local repository health.") from None
        if result.returncode:
            # Git stderr can contain remote-controlled text; never surface it.
            if args[0] in ("clone", "fetch", "push") and result.returncode == 128:
                raise Transient("Git transport or authentication failed; retry with bounded backoff.")
            raise Error("Git rejected the operation. Check write access, branch protection and divergence.")
        return result.stdout.strip()

    def publish(self, stage: Path) -> tuple[str, bool]:
        self.root.mkdir(parents=True, exist_ok=True)
        self.git("check-ref-format", "refs/heads/" + self.branch)
        if not self.checkout.exists():
            self.git("clone", "--no-tags", "--single-branch", "--branch", self.branch, "--", self.url,
                     str(self.checkout))
        if self.git("remote", "get-url", "origin", cwd=self.checkout) != self.url:
            raise Error("Local repository origin differs from the authorized destination.")
        if self.git("status", "--porcelain", cwd=self.checkout):
            raise Error("Local checkout contains unexpected changes; inspect it before retrying.")
        self.git("fetch", "--no-tags", "origin", cwd=self.checkout)
        self.git("merge", "--ff-only", "refs/remotes/origin/" + self.branch, cwd=self.checkout)
        with tempfile.TemporaryDirectory(prefix="publish-", dir=self.root) as directory:
            work = Path(directory) / "tree"
            self.git("worktree", "add", "--detach", str(work), "HEAD", cwd=self.checkout)
            try:
                managed = work / "gitkeeper"
                if managed.exists() or managed.is_symlink():
                    if managed.is_symlink() or not managed.is_dir() or any(p.is_symlink() for p in managed.rglob("*")):
                        raise Error("Unsafe managed area in destination repository.")
                    manifest = read_json(managed / "manifest.json")
                    expected = set(manifest.get("checksums", {})) | {"manifest.json"}
                    actual = {p.relative_to(managed).as_posix() for p in managed.rglob("*") if p.is_file()}
                    if manifest.get("schema") != 1 or not manifest.get("gitkeeper_version") or expected != actual:
                        raise Error("Destination managed area contains foreign files; no files removed.")
                    shutil.rmtree(managed)
                shutil.copytree(stage, managed)
                self.git("add", "--all", "--", "gitkeeper", cwd=work)
                if not self.git("diff", "--cached", "--name-only", cwd=work):
                    return self.git("rev-parse", "HEAD", cwd=work), False
                self.git("commit", "-m", "Track Zabbix configuration", cwd=work)
                commit = self.git("rev-parse", "HEAD", cwd=work)
                self.git("push", "origin", "HEAD:refs/heads/" + self.branch, cwd=work)
                self.git("merge", "--ff-only", commit, cwd=self.checkout)
                return commit, True
            finally:
                self.git("worktree", "remove", "--force", str(work), cwd=self.checkout)
