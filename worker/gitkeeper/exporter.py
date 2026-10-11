"""Native exports plus explicit read collectors; never publish partial enumeration."""

import hashlib
import json
from collections import Counter
from pathlib import Path

from zabbix_utils import ProcessingError, ZabbixAPI

from . import __version__
from .policy import encode, sanitize
from .storage import Error, Transient, api_url


class Incomplete(Error):
    pass


class Exporter:
    def __init__(self, credentials: dict, api=None):
        self.api = api or ZabbixAPI(url=api_url(credentials["url"]), timeout=30)
        self.version = str(self.api.apiinfo.version())
        self.series = ".".join(self.version.split(".")[:2])
        if self.series not in ("7.0", "7.4"):
            raise Error("Zabbix version has no reviewed export policy. Targets are 7.0 and 7.4.")
        if api is None:
            self.api.login(token=credentials["token"])
        # authentication.get is Super-admin-only on both target APIs. Fail closed
        # rather than treating a permission-filtered enumeration as whole-instance.
        self.api.authentication.get(output=["authentication_type"])

    def check_permissions(self, categories: list[str]) -> None:
        methods = {"hosts": ("host", "hostgroup", "item", "trigger", "discoveryrule", "itemprototype", "triggerprototype"),
                   "templates": ("template", "templategroup", "item", "trigger", "discoveryrule", "itemprototype", "triggerprototype"),
                   "actions": ("action",), "media_types": ("mediatype",), "users": ("user", "usergroup", "role")}
        for category in categories:
            for method in methods[category]:
                getattr(self.api, method).get(countOutput=True)

    def enumerate(self, method: str, id_field: str, **params) -> list:
        get = getattr(self.api, method).get
        scope = {k: v for k, v in params.items() if k != "output" and not k.startswith("select")}
        before = int(get(countOutput=True, **scope))
        rows = get(**params)
        after = int(get(countOutput=True, **scope))
        if not isinstance(rows, list) or len(rows) != before or before != after:
            raise Incomplete("Enumeration incomplete or changed during export; retry when configuration is stable.")
        ids = [str(r[id_field]) for r in rows]
        if len(set(ids)) != len(ids) or any(not i.isdigit() for i in ids):
            raise Incomplete("Invalid or duplicated object IDs in enumeration.")
        return sorted(rows, key=lambda r: int(r[id_field]))

    def verify_native(self, host_id: str, obj: dict, data: dict) -> None:
        scope = {"hostids": [host_id], "inherited": False}
        items = self.enumerate("item", "itemid", output=["itemid", "key_"], filter={"flags": 0}, **scope)
        discoveries = self.enumerate("discoveryrule", "itemid", output=["itemid", "key_"], **scope)
        for expected, exported in ((items, obj.get("items", [])), (discoveries, obj.get("discovery_rules", []))):
            if Counter(r["key_"] for r in expected) != Counter(r["key"] for r in exported):
                raise Incomplete("Native item/discovery coverage does not match API enumeration.")
        triggers = self.enumerate("trigger", "triggerid", output=["triggerid", "description"],
                                  filter={"flags": 0}, **scope)
        emitted = list(data.get("triggers", [])) + list(obj.get("triggers", []))
        for item in obj.get("items", []):
            emitted.extend(item.get("triggers", []))
        if Counter(r["description"] for r in triggers) - Counter(r["name"] for r in emitted):
            raise Incomplete("Native trigger coverage does not match API enumeration.")
        for discovery in discoveries:
            native = next(r for r in obj.get("discovery_rules", []) if r["key"] == discovery["key_"])
            expected = self.enumerate("itemprototype", "itemid", discoveryids=[discovery["itemid"]],
                                      output=["itemid", "key_"])
            if Counter(r["key_"] for r in expected) != Counter(r["key"] for r in native.get("item_prototypes", [])):
                raise Incomplete("Native item prototype coverage does not match API enumeration.")
            expected_triggers = self.enumerate("triggerprototype", "triggerid", discoveryids=[discovery["itemid"]],
                                               output=["triggerid", "description"])
            prototypes = list(native.get("trigger_prototypes", []))
            for item in native.get("item_prototypes", []):
                prototypes.extend(item.get("trigger_prototypes", []))
            if Counter(r["description"] for r in expected_triggers) - Counter(r["name"] for r in prototypes):
                raise Incomplete("Native trigger prototype coverage does not match API enumeration.")

    def native(self, category: str, stage: Path, excluded: set) -> int:
        family, method, id_field = ("hosts", "host", "hostid") if category == "hosts" else (
            "templates", "template", "templateid")
        rows = self.enumerate(method, id_field, output=[id_field, "host"])
        groups = (("hostgroup", "groupid", "host_groups"),) if category == "hosts" else (
            ("templategroup", "groupid", "template_groups"),)
        group_count = 0
        for group_method, group_id, group_key in groups:
            objects = self.enumerate(group_method, group_id, output=[group_id, "name", "uuid"])
            data = {"version": self.series, group_key: [{k: r[k] for k in ("name", "uuid")} for r in objects]}
            self.write(stage / category / f"{group_key}.json", sanitize(data, "export", excluded))
            group_count += len(objects)
        # One native object per file gives stable names and bounded export batches.
        for row in rows:
            raw = self.api.configuration.export(format="json", options={family: [row[id_field]]})
            try:
                data = json.loads(raw)["zabbix_export"]
            except (ValueError, KeyError, TypeError):
                raise Incomplete("Malformed native export response.") from None
            returned = data.get(family, [])
            if len(returned) != 1 or returned[0].get("host" if category == "hosts" else "template") != row["host"]:
                raise Incomplete("Native export did not include the requested object.")
            self.verify_native(row[id_field], returned[0], data)
            self.write(stage / category / f"{row[id_field]}.json", sanitize(data, "export", excluded))
        again = self.enumerate(method, id_field, output=[id_field, "host"])
        if rows != again:
            raise Incomplete("Object enumeration changed during native export.")
        return len(rows) + group_count

    def collected(self, category: str, stage: Path, excluded: set) -> int:
        if category == "actions":
            collectors = [("action", "actionid", "action", {"output": "extend", "selectFilter": "extend",
                           "selectOperations": "extend", "selectRecoveryOperations": "extend",
                           "selectUpdateOperations": "extend"})]
        elif category == "media_types":
            collectors = [("mediatype", "mediatypeid", "media", {
                "output": ["mediatypeid", "name", "type", "status", "smtp_port", "smtp_security",
                           "smtp_verify_peer", "smtp_verify_host", "smtp_authentication", "maxsessions",
                           "maxattempts", "attempt_interval", "message_format", "timeout", "process_tags",
                           "show_event_menu", "provider"], "selectMessageTemplates": "extend"})]
        else:
            collectors = [
                ("user", "userid", "user", {"output": ["userid", "username", "name", "surname", "roleid",
                 "autologin", "autologout", "lang", "refresh", "theme", "rows_per_page", "userdirectoryid"],
                 "selectUsrgrps": ["usrgrpid"], "selectMedias": ["mediaid", "mediatypeid", "active", "severity", "period"]}),
                ("usergroup", "usrgrpid", "user_group", {"output": ["usrgrpid", "name", "gui_access", "users_status",
                 "debug_mode", "userdirectoryid", "mfa_status", "mfaid"], "selectHostGroupRights": ["id", "permission"],
                 "selectTemplateGroupRights": ["id", "permission"], "selectTagFilters": ["groupid", "tag", "value"]}),
                ("role", "roleid", "role", {"output": ["roleid", "name", "type", "readonly"], "selectRules": "extend"})
            ]
        total = 0
        for method, id_field, context, params in collectors:
            rows = self.enumerate(method, id_field, **params)
            for row in rows:
                self.write(stage / category / method / f"{row[id_field]}.json", sanitize(row, context, excluded))
            total += len(rows)
        if category == "media_types":
            excluded.add("/media_types/credentials_scripts_parameters_endpoints")
        if category == "users":
            excluded.add("/users/passwords_tokens_mfa_secrets_media_recipients")
        return total

    @staticmethod
    def write(path: Path, value) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(encode(value), encoding="utf-8", newline="\n")

    def export(self, categories: list[str], stage: Path, progress=None) -> dict:
        coverage = {}
        for category in categories:
            excluded = set()
            if progress:
                progress(category, "Running", {})
            try:
                count = self.native(category, stage, excluded) if category in ("hosts", "templates") else (
                    self.collected(category, stage, excluded))
                coverage[category] = {"objects": count, "enumeration": "verified", "state": "exported",
                                      "excluded_fields": sorted(excluded)}
            except Exception as exc:
                if progress:
                    progress(category, "Incomplete" if isinstance(exc, Incomplete) else "Failed", {})
                if isinstance(exc, Error):
                    raise
                if isinstance(exc, ProcessingError):
                    raise Transient(f"Zabbix transport failed while exporting {category}; retry later.") from None
                raise Error(f"Zabbix export failed for {category}. Check API access and version policy.") from None
            if progress:
                progress(category, "Exported", coverage[category])
        checksums = {str(p.relative_to(stage)).replace("\\", "/"): hashlib.sha256(p.read_bytes()).hexdigest()
                     for p in sorted(stage.rglob("*.json"))}
        manifest = {"schema": 1, "gitkeeper_version": __version__, "zabbix_version": self.version,
                    "coverage": coverage, "checksums": checksums, "restore_ready": False,
                    "guarantee": "Complete enumeration of selected scope; confidential payloads omitted. No full recovery."}
        self.write(stage / "manifest.json", manifest)
        return coverage
