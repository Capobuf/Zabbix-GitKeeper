import copy
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from zabbix_utils import APIRequestError, ProcessingError

from gitkeeper.exporter import Exporter, Incomplete
from gitkeeper.git import Publisher
from gitkeeper.github import GitHub, Reconnect
from gitkeeper.policy import OMITTED, UNREADABLE, encode, sanitize
from gitkeeper.storage import Error, Transient, atomic_json, command, process_lock, read_json, settings
from gitkeeper.worker import Worker

SENTINEL = "TEST_SECRET_NEVER_PUBLISH"
TOKEN = "a" * 64


class Collection:
    def __init__(self, rows):
        self.rows = rows
        self.count_delta = 0
        self.calls = []

    def get(self, **params):
        self.calls.append(params)
        if params.get("countOutput"):
            return len(self.rows) + self.count_delta
        output = params.get("output")
        if isinstance(output, list):
            selected = [{k: v for k, v in row.items() if k in output or isinstance(v, (list, dict))}
                        for row in self.rows]
            return copy.deepcopy(selected)
        return copy.deepcopy(self.rows)


class FakeAPI:
    def __init__(self, version="7.0.0"):
        self.apiinfo = SimpleNamespace(version=lambda: version)
        self.authentication = SimpleNamespace(get=Mock(return_value={"authentication_type": "0"}))
        self.host = Collection([{"hostid": "1", "host": "server"}, {"hostid": "2", "host": "router"}])
        self.template = Collection([{"templateid": "10", "host": "Linux"}])
        self.hostgroup = Collection([{"groupid": "3", "name": "Servers", "uuid": "a" * 32}])
        self.templategroup = Collection([{"groupid": "4", "name": "Templates", "uuid": "b" * 32}])
        self.item = Collection([{"itemid": "100", "key_": SENTINEL}, {"itemid": "101", "key_": SENTINEL + "_RAM"}])
        self.discoveryrule = Collection([{"itemid": "102", "key_": SENTINEL}])
        self.trigger = Collection([{"triggerid": "103", "description": "High CPU"}])
        self.itemprototype = Collection([])
        self.triggerprototype = Collection([])
        self.action = Collection([{"actionid": "5", "name": "Notify", "eventsource": "0", "status": "0",
            "filter": {"evaltype": "0", "conditions": [{"conditiontype": "1", "operator": "0",
                                                       "value": SENTINEL, "formulaid": "A"}]},
            "operations": [{"operationtype": "0", "opmessage": {"default_msg": "0", "subject": SENTINEL,
                                                                       "message": SENTINEL}},
                           {"operationtype": "1", "opcommand": {"scriptid": "1"},
                            "opcommand_hst": [{"opcommand_hstid": "1", "hostid": "1"}],
                            "opcommand_grp": [{"opcommand_grpid": "2", "groupid": "3"}]}],
            "recovery_operations": [], "update_operations": []}])
        self.mediatype = Collection([{"mediatypeid": "6", "name": "Mail", "type": "0", "status": "0",
            "message_templates": [{"eventsource": "0", "recovery": "0", "subject": SENTINEL, "message": SENTINEL}]}])
        self.user = Collection([{"userid": "7", "username": "Admin", "roleid": "3", "usrgrps": [{"usrgrpid": "8"}],
                                 "medias": [{"mediatypeid": "6", "active": "0", "severity": "63", "period": "1-7,00:00-24:00"}]}])
        self.usergroup = Collection([{"usrgrpid": "8", "name": "Admins", "hostgroup_rights": [{"id": "3", "permission": "3"}],
                                      "templategroup_rights": [], "tag_filters": []}])
        self.role = Collection([{"roleid": "3", "name": "Super admin", "type": "3", "readonly": "1",
                                "rules": {"api.access": "1", "api": [SENTINEL], "ui": [{"name": "hosts", "status": "1"}]}}])
        self.configuration = SimpleNamespace(export=self.native)
        self.version = version
        self.delay = "1m"
        self.reverse = False
        self.fail = False

    def native(self, **params):
        if self.fail:
            raise RuntimeError(SENTINEL)
        family, ids = next(iter(params["options"].items()))
        host = next(r["host"] for r in (self.host.rows if family == "hosts" else self.template.rows)
                    if r.get("hostid", r.get("templateid")) == ids[0])
        items = [{"uuid": "c" * 32, "name": "CPU", "type": "ZABBIX_PASSIVE", "key": SENTINEL,
                  "delay": self.delay, "password": SENTINEL,
                  "preprocessing": [{"type": "JAVASCRIPT", "parameters": [SENTINEL]}],
                  "triggers": [{"name": "High CPU", "expression": SENTINEL, "priority": "WARNING"}]},
                 {"uuid": "d" * 32, "name": "RAM", "key": SENTINEL + "_RAM", "delay": "1m"}]
        if self.reverse:
            items.reverse()
        obj = {"host" if family == "hosts" else "template": host, "name": host, "items": items,
               "macros": [{"macro": "{$SECRET}", "type": "SECRET_TEXT", "value": SENTINEL}],
               "interfaces": [{"interface_ref": "if1", "type": "SNMP", "details": {"community": SENTINEL}}],
               "httptests": [{"http_password": SENTINEL}], "inventory": {"notes": SENTINEL},
               "discovery_rules": [{"name": "Discovery", "key": SENTINEL, "item_prototypes": [],
                                    "trigger_prototypes": [], "graph_prototypes": [], "host_prototypes": []}]}
        if self.version.startswith("7.4") and family == "templates":
            obj.update(wizard_ready="YES", readme=SENTINEL)
            obj["macros"][0]["config"] = {"value": SENTINEL}
        return json.dumps({"zabbix_export": {"version": self.version[:3], "date": SENTINEL, family: [obj]}})


class CoreTests(unittest.TestCase):
    def test_configuration_and_queue_whitelist(self):
        valid = {"repository_id": 10, "categories": ["hosts"], "interval": 15}
        self.assertEqual(settings(valid), valid)
        for change in ({"interval": True}, {"interval": 7}, {"categories": ["restore"]},
                       {"categories": []}, {"repository_id": -1}, {"shell": "echo bad"}):
            with self.assertRaises(Error):
                settings({**valid, **change})
        with self.assertRaises(Error):
            command({"id": "a" * 32, "operation": "shell", "params": {}})
        with self.assertRaises(Error):
            command({"id": "a" * 32, "operation": "set_zabbix", "params": {"url": "http://localhost/", "token": TOKEN}})

    def test_determinism_secrets_and_version_fixtures(self):
        for version in ("7.0.0", "7.4.0"):
            api = FakeAPI(version)
            with tempfile.TemporaryDirectory() as directory:
                a, b = Path(directory) / "a", Path(directory) / "b"
                coverage = Exporter({}, api).export(["hosts", "templates", "actions", "media_types", "users"], a)
                api.reverse = True
                Exporter({}, api).export(["hosts", "templates", "actions", "media_types", "users"], b)
                first = {p.relative_to(a): p.read_bytes() for p in a.rglob("*.json")}
                second = {p.relative_to(b): p.read_bytes() for p in b.rglob("*.json")}
                self.assertEqual(first, second)
                text = b"".join(first.values()).decode()
                self.assertNotIn(SENTINEL, text)
                self.assertIn(UNREADABLE, text)
                self.assertGreater(coverage["hosts"]["objects"], 1)
                self.assertIn("selectRecoveryOperations", api.action.calls[1])

    def test_unknown_field_fails_closed_without_echoing_values(self):
        with self.assertRaises(Error) as raised:
            sanitize({"name": "Safe", "unknown": SENTINEL}, "host", set())
        self.assertNotIn(SENTINEL, str(raised.exception))
        self.assertEqual(sanitize({"script": SENTINEL, "parameters": [SENTINEL]}, "media", set()),
                         {"script": OMITTED, "parameters": OMITTED})

    def test_ordered_preprocessing_is_not_sorted(self):
        data = [{"type": "MULTIPLIER"}, {"type": "RTRIM"}]
        self.assertEqual(sanitize(data, "preprocessing", set()), data)

    def test_incomplete_enumeration_does_not_succeed(self):
        api = FakeAPI()
        api.host.count_delta = 1
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(Incomplete):
                Exporter({}, api).export(["hosts"], Path(directory))
            self.assertFalse((Path(directory) / "manifest.json").exists())

    def test_native_export_missing_an_item_fails(self):
        api = FakeAPI()
        api.item.rows.append({"itemid": "999", "key_": "missing"})
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(Incomplete):
                Exporter({}, api).export(["hosts"], Path(directory))

    def test_api_permission_failure_is_not_masked(self):
        api = FakeAPI()
        api.authentication.get.side_effect = RuntimeError(SENTINEL)
        with self.assertRaises(RuntimeError):
            Exporter({}, api)

    def test_api_startup_errors_are_safe_and_transport_is_retryable(self):
        api = FakeAPI()
        api.authentication.get.side_effect = APIRequestError(SENTINEL)
        with self.assertRaises(Error) as result:
            Exporter({}, api)
        self.assertNotIn(SENTINEL, str(result.exception))
        api.authentication.get.side_effect = ProcessingError(SENTINEL)
        with self.assertRaises(Transient) as result:
            Exporter({}, api)
        self.assertNotIn(SENTINEL, str(result.exception))

    @unittest.skipIf(os.name == "nt", "POSIX flock requires Linux; covered by Linux CI")
    def test_real_process_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "lock"
            with process_lock(path) as first:
                self.assertTrue(first)
                code = "from pathlib import Path; from gitkeeper.storage import process_lock; " \
                       "c=process_lock(Path(__import__('sys').argv[1])); print(c.__enter__())"
                result = subprocess.check_output([__import__("sys").executable, "-c", code, str(path)], text=True)
                self.assertEqual(result.strip(), "False")
            with process_lock(path) as second:
                self.assertTrue(second)


class GitHubTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.github = GitHub("fixture-client-id", Path(self.temp.name))

    @patch("gitkeeper.github.request")
    @patch("gitkeeper.github.time.time", return_value=100)
    def test_device_pending_slowdown_and_expiry(self, clock, request):
        request.return_value = {"device_code": SENTINEL, "user_code": "ABCD-EFGH",
            "verification_uri": "https://github.com/login/device", "expires_in": 900, "interval": 5}
        public = self.github.connect()
        self.assertNotIn(SENTINEL, encode(public))
        request.return_value = {"error": "slow_down"}
        self.github.poll()
        self.assertEqual(read_json(self.github.device_path)["interval"], 10)
        request.reset_mock()
        self.github.poll()
        request.assert_not_called()
        clock.return_value = 110
        request.return_value = {"error": "authorization_pending"}
        self.assertIsNotNone(self.github.poll())
        clock.return_value = 1001
        with self.assertRaises(Reconnect):
            self.github.poll()

    @patch("gitkeeper.github.request")
    def test_refresh_without_secret_and_revocation(self, request):
        atomic_json(self.github.path, {"access_token": "ghu_fixture", "refresh_token": "ghr_fixture", "expires_at": 0})
        request.return_value = {"access_token": "ghu_new", "refresh_token": "ghr_new", "expires_in": 28800}
        self.assertEqual(self.github.token(), "ghu_new")
        self.assertNotIn("client_secret", request.call_args.args[2])
        request.side_effect = Reconnect("GitHub authorization expired or revoked. Reconnect GitHub.")
        with self.assertRaises(Reconnect):
            self.github.api("/user")
        self.assertFalse(self.github.path.exists())

    @patch("gitkeeper.github.request")
    def test_refresh_rejection_stops_retrying(self, request):
        atomic_json(self.github.path, {"access_token": "ghu_fixture", "refresh_token": "ghr_fixture", "expires_at": 0})
        request.side_effect = Error("GitHub rejected refresh.")
        with self.assertRaises(Reconnect):
            self.github.token()
        self.assertFalse(self.github.path.exists())

    def test_paginated_repository_privacy_and_permissions(self):
        repos = [{"id": i, "full_name": f"owner/repo{i}", "private": True, "permissions": {"push": True},
                  "default_branch": "main"} for i in range(101)]
        repos.append({**repos[0], "id": 200, "private": False})
        repos.append({**repos[0], "id": 201, "permissions": {"push": False}})
        def api(path):
            if path.startswith("/user/installations?"):
                return {"installations": [{"id": 5, "permissions": {"contents": "write"}}], "total_count": 1}
            page = int(path.split("page=")[-1])
            return {"repositories": repos[(page - 1) * 100:page * 100], "total_count": len(repos)}
        self.github.api = Mock(side_effect=api)
        self.assertEqual(len(self.github.repositories()), 101)
        with self.assertRaises(Error):
            self.github.repository(200)
        with self.assertRaises(Error):
            self.github.repository(201)

    def test_creation_always_private_then_checks_app_access(self):
        self.github.api = Mock(return_value={"id": 42})
        self.github.repository = Mock(return_value={"id": 42, "private": True})
        self.github.create_repository("backup")
        self.assertEqual(self.github.api.call_args.args[2], {"name": "backup", "private": True, "auto_init": True})
        self.github.repository.assert_called_once_with(42)


class LocalPublisher(Publisher):
    """Only the transport is replaced: tests use real Git against a local bare remote."""

    def __init__(self, root, remote):
        super().__init__(root, {"id": 42, "private": True, "full_name": "fixture/backup", "default_branch": "main"}, "fixture")
        self.url = str(remote)


class GitTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.remote = self.root / "remote.git"
        self.seed = self.root / "seed"
        self.seed.mkdir()
        self.git("init", "--bare", "--initial-branch=main", str(self.remote))
        self.git("init", "--initial-branch=main", str(self.seed))
        (self.seed / "README.md").write_text("Foreign file, preserve me.")
        self.git("add", ".", cwd=self.seed)
        self.git("commit", "-m", "Initialize", cwd=self.seed)
        self.git("remote", "add", "origin", str(self.remote), cwd=self.seed)
        self.git("push", "origin", "main", cwd=self.seed)
        self.publisher = LocalPublisher(self.root / "checkouts", self.remote)

    @staticmethod
    def git(*args, cwd=None):
        return subprocess.check_output(["git", "-c", "user.name=Fixture", "-c", "user.email=fixture@localhost",
                                       "-c", "commit.gpgsign=false", *args], cwd=cwd,
                                      stderr=subprocess.DEVNULL, text=True).strip()

    def test_unchanged_snapshot_one_commit_and_real_changes_diff(self):
        api = FakeAPI()
        exporter = Exporter({}, api)
        first_stage = self.root / "first"
        exporter.export(["hosts", "templates", "actions"], first_stage)
        first, changed = self.publisher.publish(first_stage)
        self.assertTrue(changed)
        same, changed = self.publisher.publish(first_stage)
        self.assertEqual(same, first)
        self.assertFalse(changed)
        self.assertEqual(self.git("rev-list", "--count", "main", cwd=self.remote), "2")
        api.delay = "2m"
        api.action.rows[0]["status"] = "1"
        second_stage = self.root / "second"
        exporter.export(["hosts", "templates", "actions"], second_stage)
        second, changed = self.publisher.publish(second_stage)
        self.assertTrue(changed)
        diff = self.git("diff", "--name-only", first, second, cwd=self.remote)
        for category in ("hosts", "templates", "actions"):
            self.assertIn("gitkeeper/" + category, diff)
        self.assertIn("preserve me", self.git("show", "main:README.md", cwd=self.remote))

    def test_export_error_preserves_last_snapshot(self):
        api = FakeAPI()
        exporter = Exporter({}, api)
        stage = self.root / "first"
        exporter.export(["hosts"], stage)
        commit, _ = self.publisher.publish(stage)
        api.fail = True
        with self.assertRaises(Error):
            exporter.export(["hosts"], self.root / "bad")
        self.assertEqual(self.git("rev-parse", "main", cwd=self.remote), commit)
        self.assertEqual(self.git("rev-parse", "HEAD", cwd=self.publisher.checkout), commit)

    def test_push_failure_does_not_change_valid_checkout(self):
        stage = self.root / "first"
        exporter = Exporter({}, FakeAPI())
        exporter.export(["hosts"], stage)
        commit, _ = self.publisher.publish(stage)
        (stage / "hosts/1.json").write_text('{}\n')
        real_git = self.publisher.git
        def reject(*args, **kwargs):
            if args[0] == "push":
                raise Error("Git rejected the operation.")
            return real_git(*args, **kwargs)
        with patch.object(self.publisher, "git", side_effect=reject), self.assertRaises(Error):
            self.publisher.publish(stage)
        self.assertEqual(self.git("rev-parse", "HEAD", cwd=self.publisher.checkout), commit)
        self.assertEqual(self.git("rev-parse", "main", cwd=self.remote), commit)

    def test_foreign_managed_file_is_preserved(self):
        stage = self.root / "first"
        Exporter({}, FakeAPI()).export(["hosts"], stage)
        self.publisher.publish(stage)
        self.git("pull", "--ff-only", "origin", "main", cwd=self.seed)
        (self.seed / "gitkeeper/foreign.txt").write_text("Do not delete.")
        self.git("add", ".", cwd=self.seed)
        self.git("commit", "-m", "Foreign file", cwd=self.seed)
        self.git("push", "origin", "main", cwd=self.seed)
        with self.assertRaises(Error):
            self.publisher.publish(stage)
        self.assertEqual(self.git("show", "main:gitkeeper/foreign.txt", cwd=self.remote), "Do not delete.")

    def test_public_target_rejected_before_git(self):
        with self.assertRaises(Error):
            Publisher(self.root, {"private": False}, "fixture")

    @patch("gitkeeper.worker.process_lock")
    def test_queued_worker_to_real_git_and_periodic_unchanged_snapshot(self, lock):
        lock.return_value.__enter__.return_value = True
        root = self.root / "state"
        for directory in ("private", "queue", "public"):
            (root / directory).mkdir(parents=True, exist_ok=True)
        installation = self.root / "worker.json"
        atomic_json(installation, {"state_dir": str(root), "client_id": "fixture"})
        atomic_json(root / "private/zabbix.json", {"url": "https://zabbix.example/api_jsonrpc.php", "token": TOKEN})
        worker = Worker(installation)
        atomic_json(worker.github.path, {"access_token": "ghu_fixture"})
        worker.github.repository = Mock(return_value={"id": 42, "private": True, "full_name": "fixture/backup",
                                                     "default_branch": "main"})
        atomic_json(root / "queue" / ("a" * 32 + ".json"), {"id": "a" * 32, "operation": "save", "params": {
            "settings": {"repository_id": 42, "categories": ["hosts", "templates", "actions"], "interval": 15}}})
        exporter = Exporter({}, FakeAPI())
        with patch("gitkeeper.worker.Exporter", return_value=exporter), patch("gitkeeper.worker.Publisher",
                side_effect=lambda path, repo, token: LocalPublisher(path, self.remote)):
            worker.tick()
            self.assertEqual(worker.status["state"], "Completed")
            self.assertEqual(worker.status["last_request"], "a" * 32)
            self.assertGreater(worker.config["next_sync"], worker.status["last_success"])
            first = worker.status["last_commit"]
            worker.config["next_sync"] = 0
            atomic_json(worker.config_path, worker.config)
            worker.tick()
            self.assertEqual(worker.status["last_commit"], first)
            self.assertFalse(worker.status["changed"])
        self.assertEqual(self.git("rev-list", "--count", "main", cwd=self.remote), "2")
        self.assertNotIn(TOKEN, worker.public.read_text())


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for directory in ("private", "queue", "public"):
            (self.root / directory).mkdir()
        self.install = self.root / "worker.json"
        atomic_json(self.install, {"state_dir": str(self.root), "client_id": "fixture"})
        self.worker = Worker(self.install)

    def test_revocation_and_library_errors_are_visible_without_secrets(self):
        self.worker.failure(RuntimeError(SENTINEL))
        self.assertNotIn(SENTINEL, self.worker.public.read_text())
        self.worker.failure(Reconnect("GitHub authorization expired or revoked. Reconnect GitHub."))
        state = read_json(self.worker.public)
        self.assertEqual(state["state"], "Failed")
        self.assertEqual(state["github"], "Disconnected")
        self.assertTrue(state["reconnect"])

    def test_partial_state_is_not_completed(self):
        self.worker.failure(Incomplete("Enumeration incomplete."))
        self.assertEqual(read_json(self.worker.public)["state"], "Partial / Incomplete")

    def test_queue_rejects_arbitrary_commands_and_consumes_credentials(self):
        request_id = "a" * 32
        atomic_json(self.root / "queue" / (request_id + ".json"),
                    {"id": request_id, "operation": "shell", "params": {"command": SENTINEL}})
        self.worker.drain()
        self.assertEqual(self.worker.status["state"], "Failed")
        self.assertNotIn(SENTINEL, self.worker.public.read_text())
        self.assertEqual(list((self.root / "queue").iterdir()), [])
        self.assertEqual(list((self.root / "private").glob("request-*")), [])

    @patch("gitkeeper.worker.Exporter")
    def test_token_is_never_in_public_state(self, exporter):
        exporter.return_value.api = FakeAPI()
        exporter.return_value.version = "7.0.0"
        self.worker.execute(command({"id": "a" * 32, "operation": "set_zabbix",
            "params": {"url": "https://zabbix.example/api_jsonrpc.php", "token": TOKEN}}))
        self.assertNotIn(TOKEN, self.worker.public.read_text())
        self.assertEqual(read_json(self.root / "private/zabbix.json")["token"], TOKEN)

    @patch("gitkeeper.worker.process_lock")
    @patch("gitkeeper.worker.Exporter")
    def test_save_queues_initial_sync_and_backoff_is_bounded(self, exporter, lock):
        lock.return_value.__enter__.return_value = True
        self.worker.github.repository = Mock(return_value={"private": True})
        atomic_json(self.root / "private/zabbix.json", {"token": TOKEN})
        self.worker.execute(command({"id": "a" * 32, "operation": "save", "params": {
            "settings": {"repository_id": 42, "interval": 15, "categories": ["hosts"]}}}))
        self.assertTrue(self.worker.config["sync_pending"])
        self.assertEqual(self.worker.status["state"], "Queued")
        atomic_json(self.worker.github.path, {"access_token": "ghu_fixture"})
        self.worker.github.token = Mock(return_value="ghu_fixture")
        self.worker.sync = Mock(side_effect=Transient("Temporary outage."))
        for attempt in range(4):
            self.worker.config["retry_at"] = 0
            atomic_json(self.worker.config_path, self.worker.config)
            self.worker.tick()
        self.assertEqual(self.worker.config["retry_count"], 4)
        self.assertFalse(self.worker.config["sync_pending"])
        self.assertNotIn("next_sync", self.worker.config)


if __name__ == "__main__":
    unittest.main()
