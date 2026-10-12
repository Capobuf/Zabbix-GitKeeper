import copy
import json
import os
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch
from urllib.error import HTTPError

from zabbix_utils import APIRequestError, ProcessingError

from gitkeeper.exporter import Exporter, Incomplete
from gitkeeper.git import Publisher
from gitkeeper.github import GitHub, Reconnect, request as github_request
from gitkeeper.policy import OMITTED, UNREADABLE, encode, sanitize
from gitkeeper.storage import Error, Transient, api_url, atomic_json, command, process_lock, read_json, settings
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
        rows = [row for row in self.rows if all(key not in row or str(row[key]) == str(value)
                for key, value in params.get("filter", {}).items())]
        if params.get("countOutput"):
            return len(rows) + self.count_delta
        output = params.get("output")
        if isinstance(output, list):
            selected = [{k: v for k, v in row.items() if k in output or isinstance(v, (list, dict))}
                        for row in rows]
            return copy.deepcopy(selected)
        return copy.deepcopy(rows)


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
        self.discoveryruleprototype = Collection([])
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
        for client_id in (None, "", "short value", "x" * 129, "$(command)"):
            with self.assertRaises(Error):
                command({"id": "a" * 32, "operation": "set_github_app", "params": {"client_id": client_id}})
        with self.assertRaises(Error):
            command({"id": "a" * 32, "operation": "set_github_app",
                     "params": {"client_id": "fixture", "client_secret": SENTINEL}})

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

    def test_native_host_prototype_group_links_and_safe_policy_error(self):
        for version in ("7.0.0", "7.4.15"):
            with self.subTest(version=version):
                api = FakeAPI(version)
                original = api.native
                unexpected = False

                def native(**params):
                    data = json.loads(original(**params))
                    family = next(iter(params["options"]))
                    group = {"name": "Discovered hosts"}
                    if unexpected:
                        group[SENTINEL] = SENTINEL
                    data["zabbix_export"][family][0]["discovery_rules"][0]["host_prototypes"] = [{
                        "host": "discovered-{#ID}", "name": "Discovered host",
                        "group_links": [{"group": group}], "group_prototypes": [{"name": "Hosts/{#ID}"}],
                        "macros": [{"macro": "{$PASSWORD}", "value": SENTINEL}]
                    }]
                    return json.dumps(data)

                api.configuration.export = native
                with tempfile.TemporaryDirectory() as directory:
                    stage = Path(directory)
                    Exporter({}, api).export(["hosts", "templates"], stage)
                    for family, object_id in (("hosts", "1"), ("templates", "10")):
                        data = read_json(stage / family / f"{object_id}.json")
                        prototype = data[family][0]["discovery_rules"][0]["host_prototypes"][0]
                        self.assertEqual(prototype["group_links"], [{"group": {"name": "Discovered hosts"}}])
                        self.assertNotIn(SENTINEL, encode(data))
                unexpected = True
                with tempfile.TemporaryDirectory() as directory:
                    stage = Path(directory)
                    progress = Mock()
                    with self.assertRaises(Error) as result:
                        Exporter({}, api).export(["templates"], stage, progress)
                    self.assertIn("Cannot export templates (Zabbix " + version[:3] + ")", str(result.exception))
                    self.assertIn("/templates[]/discovery_rules[]/host_prototypes[]/group_links[]/group",
                                  str(result.exception))
                    self.assertNotIn(SENTINEL, str(result.exception))
                    self.assertFalse((stage / "manifest.json").exists())
                    self.assertEqual(progress.call_args.args[:2], ("templates", "Failed"))

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

    def test_native_discovery_scope_checks_configured_nested_prototypes(self):
        api = FakeAPI("7.4.15")
        api.discoveryrule.rows[0]["flags"] = "1"
        api.discoveryrule.rows.append({"itemid": "110", "key_": "generated.rule", "flags": "5"})
        api.discoveryruleprototype.rows = [{"itemid": "111", "key_": "nested.rule[{#ID}]", "flags": "3"},
                                           {"itemid": "112", "key_": "generated.prototype", "flags": "7"}]
        obj = json.loads(api.native(options={"hosts": ["1"]}))["zabbix_export"]["hosts"][0]
        prototype = {"name": "Nested discovery", "key": "nested.rule[{#ID}]", "type": "NESTED",
                     "parent_discovery_rule": {"key": SENTINEL}, "item_prototypes": []}
        obj["discovery_rules"].append(prototype)
        exporter = Exporter({}, api)
        exporter.verify_native("1", obj, {})
        self.assertEqual(api.discoveryrule.calls[0]["filter"], {"flags": 1})
        self.assertEqual(api.discoveryruleprototype.calls[0]["filter"], {"flags": 3})
        self.assertNotIn(SENTINEL, encode(sanitize(prototype, "discovery", set())))
        obj["discovery_rules"].pop()
        with self.assertRaises(Incomplete):
            exporter.verify_native("1", obj, {})

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

    @patch("gitkeeper.github.urllib.request.build_opener")
    def test_device_http_failure_identifies_client_id_without_raw_errors(self, opener):
        for status in (401, 404):
            opener.return_value.open.side_effect = HTTPError("https://github.com/login/device/code",
                                                            status, SENTINEL, {}, None)
            with self.assertRaises(Error) as raised:
                github_request("https://github.com/login/device/code", "POST", {"client_id": "fixture"})
            self.assertIn(f"HTTP {status}", str(raised.exception))
            self.assertIn("Client ID (not App ID)", str(raised.exception))
            self.assertNotIn(SENTINEL, str(raised.exception))

    @patch("gitkeeper.github.request")
    def test_device_configuration_errors_are_actionable_and_not_persisted(self, request):
        for error, message in (("device_flow_disabled", "Enable Device Flow"),
                               ("incorrect_client_credentials", "Copy the Client ID")):
            request.return_value = {"error": error, "error_description": SENTINEL}
            with self.assertRaisesRegex(Error, message) as raised:
                self.github.connect()
            self.assertNotIn(SENTINEL, str(raised.exception))
            self.assertFalse(self.github.device_path.exists())

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

    def test_creation_uses_personal_or_organization_endpoint_and_checks_access(self):
        self.github.repository = Mock(return_value={"id": 42, "private": True})
        for owner, endpoint in (("Fixture", "/user/repos"), ("Test-Organization", "/orgs/Test-Organization/repos")):
            self.github.api = Mock(side_effect=[{"login": "fixture"}, {"id": 42}])
            self.github.create_repository("backup", owner)
            self.assertEqual(self.github.api.call_args.args,
                             (endpoint, "POST", {"name": "backup", "private": True, "auto_init": True}))
        self.github.api = Mock(side_effect=[{"login": "fixture"}, {"id": 42}])
        self.github.repository.side_effect = Error("App access missing")
        with self.assertRaisesRegex(Error, "Private repository created, but App access is missing"):
            self.github.create_repository("backup", "Test-Organization")


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

    def test_native_client_verifies_http_loopback_before_storing_token(self):
        calls = []

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                request = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                calls.append((request["method"], self.headers.get("Authorization")))
                result = "7.4.15" if request["method"] == "apiinfo.version" else (
                    {"authentication_type": "0"} if request["method"] == "authentication.get" else "0")
                data = json.dumps({"jsonrpc": "2.0", "id": request["id"], "result": result}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(data)

            def log_message(self, *args):
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            endpoint = f"http://127.0.0.1:{server.server_port}/zabbix/api_jsonrpc.php"
            self.worker.execute(command({"id": "a" * 32, "operation": "set_zabbix", "params": {
                "url": endpoint, "token": TOKEN}}))
            self.assertTrue(read_json(self.worker.public)["zabbix_configured"])
            self.assertEqual(read_json(self.root / "private/zabbix.json")["url"], endpoint)
            self.assertIn(("authentication.get", "Bearer " + TOKEN), calls)
            self.assertNotIn(TOKEN, self.worker.public.read_text())
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    @patch("gitkeeper.exporter.ZabbixAPI")
    def test_http_loopback_and_optional_https_certificate_verification(self, client):
        client.return_value.apiinfo.version.return_value = "7.4.15"
        for endpoint, verify in [("http://127.0.0.1/zabbix/api_jsonrpc.php", True),
                                 ("https://127.0.0.1/zabbix/api_jsonrpc.php", False),
                                 ("https://zabbix.example/api_jsonrpc.php", True)]:
            params = {"url": endpoint, "token": TOKEN, "verify_tls": verify}
            self.worker.execute(command({"id": "a" * 32, "operation": "set_zabbix", "params": params}))
            client.assert_called_with(url=endpoint, timeout=30, validate_certs=verify)
            client.return_value.login.assert_called_with(token=TOKEN)
            self.assertEqual(read_json(self.root / "private/zabbix.json"), params)
            self.assertEqual(read_json(self.worker.public)["zabbix_verify_tls"], verify)
            self.assertNotIn(TOKEN, self.worker.public.read_text())
        Exporter({"url": "https://example/api_jsonrpc.php", "token": TOKEN})
        client.assert_called_with(url="https://example/api_jsonrpc.php", timeout=30, validate_certs=True)

    def test_api_endpoint_and_tls_option_validation(self):
        for endpoint in ("http://127.0.0.1/zabbix/api_jsonrpc.php", "https://localhost:8443/api_jsonrpc.php"):
            self.assertEqual(api_url(endpoint), endpoint)
        for endpoint in ("ftp://localhost/api_jsonrpc.php", "http://user:pass@localhost/api_jsonrpc.php",
                         "http://localhost/api_jsonrpc.php?token=secret", "https://localhost/api_jsonrpc.php#secret"):
            with self.assertRaises(Error):
                api_url(endpoint)
        for verify in ("false", 0, None):
            with self.assertRaisesRegex(Error, "TLS verification"):
                command({"id": "a" * 32, "operation": "set_zabbix", "params": {
                    "url": "https://localhost/api_jsonrpc.php", "token": TOKEN, "verify_tls": verify}})

    @patch("gitkeeper.worker.Exporter")
    def test_repository_selection_before_api_setup_pauses_old_schedule(self, exporter):
        repo = {"id": 43, "full_name": "fixture/dedicated", "private": True, "default_branch": "main"}
        self.worker.github.repository = Mock(return_value=repo)
        self.worker.config = {"repository_id": 42, "categories": ["hosts"], "interval": 15,
                              "sync_pending": True, "next_sync": 1, "retry_at": 1}
        self.worker.status.update(last_commit="previous-target-commit", last_success=123,
                                  categories={"hosts": {"state": "Completed"}})
        self.worker.execute(command({"id": "a" * 32, "operation": "select_repository",
                                     "params": {"repository_id": 43}}))
        exporter.assert_not_called()
        restarted = Worker(self.install)
        self.assertEqual(restarted.config["repository_id"], 43)
        self.assertFalse(restarted.config["sync_pending"])
        for key in ("next_sync", "retry_at"):
            self.assertNotIn(key, restarted.config)
        self.assertEqual(restarted.config["categories"], ["hosts"])
        self.assertEqual(restarted.status["repository"], repo)
        for key in ("last_commit", "last_success", "categories"):
            self.assertNotIn(key, restarted.status)
        self.worker.config.update(sync_pending=True, next_sync=123)
        self.worker.execute(command({"id": "b" * 32, "operation": "select_repository",
                                     "params": {"repository_id": 43}}))
        self.assertTrue(self.worker.config["sync_pending"])
        self.assertEqual(self.worker.config["next_sync"], 123)
        self.worker.github.repository.side_effect = Error("App access missing")
        with self.assertRaises(Error):
            self.worker.execute(command({"id": "c" * 32, "operation": "select_repository",
                                         "params": {"repository_id": 44}}))
        self.assertEqual(self.worker.config["repository_id"], 43)

    @patch("gitkeeper.worker.Exporter")
    def test_created_repository_is_selected_without_starting_export(self, exporter):
        repo = {"id": 43, "private": True, "full_name": "fixture/backup", "default_branch": "main"}
        self.worker.github.create_repository = Mock(return_value=repo)
        self.worker.github.repositories = Mock(return_value=[repo])
        self.worker.execute(command({"id": "a" * 32, "operation": "create_repository",
                                     "params": {"name": "backup", "owner": "fixture"}}))
        self.worker.github.create_repository.assert_called_once_with("backup", "fixture")
        self.assertEqual(self.worker.config["repository_id"], 43)
        self.assertFalse(self.worker.config["sync_pending"])
        exporter.assert_not_called()
        with self.assertRaisesRegex(Error, "Save backup settings first"):
            self.worker.execute(command({"id": "b" * 32, "operation": "sync", "params": {}}))

    def test_repository_commands_reject_invalid_ids_and_owner_paths(self):
        for value in (0, -1, True, "42"):
            with self.assertRaises(Error):
                command({"id": "a" * 32, "operation": "select_repository", "params": {"repository_id": value}})
        for value in ("", "../org", "org/repos", "org?token=secret", 42):
            with self.assertRaises(Error):
                command({"id": "a" * 32, "operation": "create_repository",
                         "params": {"name": "backup", "owner": value}})

    def test_app_change_removes_old_authorization_and_persists_across_restart(self):
        atomic_json(self.worker.github.path, {"access_token": SENTINEL})
        atomic_json(self.worker.github.device_path, {"device_code": SENTINEL})
        atomic_json(self.root / "private/zabbix.json", {"url": "https://example/api_jsonrpc.php", "token": TOKEN})
        self.worker.config = {"repository_id": 42, "next_sync": 1, "sync_pending": True,
                              "retry_at": 1, "interval": 15, "categories": ["hosts"]}
        self.worker.status.update(github="Ready", account="fixture", repositories=[{"id": 42}])
        self.worker.execute(command({"id": "a" * 32, "operation": "set_github_app",
                                     "params": {"client_id": "Iv1.fixture"}}))
        self.assertFalse(self.worker.github.path.exists())
        self.assertFalse(self.worker.github.device_path.exists())
        self.assertEqual(self.worker.status["github"], "Disconnected")
        self.assertEqual(self.worker.status["repositories"], [])
        self.assertFalse(self.worker.config["sync_pending"])
        for key in ("repository_id", "next_sync", "retry_at"):
            self.assertNotIn(key, self.worker.config)
        restarted = Worker(self.install)
        self.assertEqual(restarted.github.client_id, "Iv1.fixture")
        self.assertEqual(restarted.config["categories"], ["hosts"])
        self.assertEqual(read_json(self.worker.public)["github_client_id"], "Iv1.fixture")
        self.assertEqual(read_json(self.root / "private/zabbix.json")["token"], TOKEN)
        self.assertNotIn(SENTINEL, self.worker.public.read_text())

    def test_saving_same_app_keeps_connection_and_schedule(self):
        atomic_json(self.worker.github.path, {"access_token": SENTINEL})
        self.worker.config = {"repository_id": 42, "next_sync": 1, "sync_pending": True}
        self.worker.status.update(github="Ready", account="fixture")
        self.worker.execute(command({"id": "a" * 32, "operation": "set_github_app",
                                     "params": {"client_id": "fixture"}}))
        self.assertEqual(read_json(self.worker.github.path)["access_token"], SENTINEL)
        self.assertEqual(self.worker.status["github"], "Ready")
        self.assertTrue(self.worker.config["sync_pending"])
        self.assertEqual(read_json(self.worker.app_path)["client_id"], "fixture")

    @patch("gitkeeper.github.request")
    def test_worker_without_app_rejects_connection_before_network(self, request):
        atomic_json(self.install, {"state_dir": str(self.root)})
        worker = Worker(self.install)
        worker.flush()
        self.assertEqual(worker.status["github_client_id"], "")
        with self.assertRaisesRegex(Error, "Save your GitHub App Client ID"):
            worker.execute(command({"id": "a" * 32, "operation": "connect", "params": {}}))
        request.assert_not_called()

    @patch("gitkeeper.worker.process_lock")
    def test_app_configuration_is_reloaded_after_lock(self, lock):
        lock.return_value.__enter__.return_value = True
        atomic_json(self.worker.app_path, {"client_id": "Iv1.changed-by-another-tick"})
        self.worker.tick()
        self.assertEqual(self.worker.github.client_id, "Iv1.changed-by-another-tick")
        self.assertEqual(read_json(self.worker.public)["github_client_id"], "Iv1.changed-by-another-tick")

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
        self.assertEqual(read_json(self.worker.public)["zabbix_url"], "https://zabbix.example/api_jsonrpc.php")
        self.worker = Worker(self.install)
        self.worker.flush()
        self.assertEqual(read_json(self.worker.public)["zabbix_url"], "https://zabbix.example/api_jsonrpc.php")
        self.worker.execute(command({"id": "b" * 32, "operation": "clear_zabbix", "params": {}}))
        self.assertIsNone(read_json(self.worker.public)["zabbix_url"])
        self.assertNotIn(TOKEN, self.worker.public.read_text())

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

    def test_token_permission_failure_preserves_verified_credentials_and_hides_api_response(self):
        credentials = {"url": "https://zabbix.example/api_jsonrpc.php", "token": TOKEN}
        atomic_json(self.root / "private/zabbix.json", credentials)
        api = FakeAPI()
        api.host.get = Mock(side_effect=APIRequestError(SENTINEL))
        exporter = Exporter({}, api)
        request_id = "c" * 32
        atomic_json(self.root / "queue" / (request_id + ".json"), {
            "id": request_id, "operation": "set_zabbix",
            "params": {"url": "https://other.example/api_jsonrpc.php", "token": "b" * 64}
        })
        with patch("gitkeeper.worker.Exporter", return_value=exporter):
            self.worker.drain()
        self.assertEqual(read_json(self.root / "private/zabbix.json"), credentials)
        status = read_json(self.worker.public)
        self.assertEqual(status["state"], "Failed")
        self.assertIn("API role permissions", status["error"])
        self.assertEqual(status["last_request"], request_id)
        self.assertNotIn(SENTINEL, self.worker.public.read_text())
        self.assertEqual(list((self.root / "queue").iterdir()), [])


if __name__ == "__main__":
    unittest.main()
