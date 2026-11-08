"""Native exports plus explicit read collectors; never publish partial enumeration."""

import hashlib
import json
import re
from collections import Counter
from pathlib import Path

from zabbix_utils import APINotSupported, APIRequestError, ProcessingError, ZabbixAPI

from . import __version__
from .errors import APICompatibility, StructureError, UnsupportedVersion, api_error
from .policy import PolicyError, encode, sanitize
from .scope import Incomplete, object_id, public_summary, relations, resolve
from .storage import Error, Transient, api_url, template_scope


def reviewed_series(version):
    if not re.fullmatch(r"(?:7\.0|7\.4|8\.0)\.[0-9]+", version):
        raise UnsupportedVersion("Unsupported Zabbix version. Reviewed series: 7.0, 7.4 and 8.0 LTS; "
                                 "use a stable patch release in one of these series.")
    return ".".join(version.split(".")[:2])


class Exporter:
    def __init__(self, credentials: dict, api=None):
        try:
            # The pinned client predates 8.0. GitKeeper owns the stricter reviewed-series
            # check below, before installing credentials or making authenticated calls.
            self.api = api or ZabbixAPI(url=api_url(credentials["url"]), timeout=30,
                                       validate_certs=credentials.get("verify_tls", True), skip_version_check=True)
            self.version = str(self.call("apiinfo.version"))
            self.series = reviewed_series(self.version)
            if api is None:
                self.api.login(token=credentials["token"])
            # This method requires Super admin in all reviewed series. Counts alone
            # cannot detect permission-filtered visibility for a lower-privilege token.
            probe = self.call("authentication.get", output=["authentication_type"])
            if not isinstance(probe, dict) or "authentication_type" not in probe:
                raise StructureError("Unrecognized authentication.get response; whole-instance visibility not verified.")
        except APINotSupported:
            raise UnsupportedVersion("The installed API client does not support this Zabbix version.") from None
        except ProcessingError:
            raise Transient("Cannot reach Zabbix API; check connectivity and certificate trust.") from None

    def call(self, method, **params):
        try:
            family, operation = method.split(".")
            return getattr(getattr(self.api, family), operation)(**params)
        except APIRequestError as exc:
            raise api_error(exc, method) from None
        except APINotSupported:
            raise APICompatibility(f"The installed API client cannot call {method} on this Zabbix version.") from None
        except ProcessingError:
            raise Transient(f"Cannot reach Zabbix API at {method}; check connectivity and certificate trust.") from None

    def check_permissions(self, categories: list[str], scope=None) -> None:
        methods = {"hosts": ("host", "hostgroup", "item", "trigger", "discoveryrule", "itemprototype", "triggerprototype", "hostprototype"),
                   "templates": ("template", "templategroup", "item", "trigger", "discoveryrule", "itemprototype", "triggerprototype", "hostprototype"),
                   "actions": ("action",), "media_types": ("mediatype",), "users": ("user", "usergroup", "role")}
        required = {method for category in categories for method in methods[category]}
        if self.series in ("7.4", "8.0") and set(categories) & {"hosts", "templates"}:
            required.add("discoveryruleprototype")
        if "templates" in categories and template_scope(scope)["mode"] == "used":
            required.update(("host", "hostprototype", "action"))
        for method in sorted(required):
            self.call(method + ".get", countOutput=True)
        if set(categories) & {"hosts", "templates"}:
            # Empty explicit IDs export no objects, but exercise method authorization.
            self.call("configuration.export", format="json", options={"hosts": []})

    def enumerate(self, method: str, id_field: str, **params) -> list:
        def get(**options):
            return self.call(method + ".get", **options)
        scope = {k: v for k, v in params.items() if k != "output" and not k.startswith("select")}
        def count():
            value = get(countOutput=True, **scope)
            if isinstance(value, bool) or not isinstance(value, (str, int)) or not str(value).isascii() or not str(value).isdigit():
                raise Incomplete("Malformed enumeration count.")
            return int(value)
        before = count()
        rows = get(**params)
        after = count()
        if not isinstance(rows, list) or len(rows) != before or before != after:
            raise Incomplete("Enumeration incomplete or changed during export; retry when configuration is stable.")
        if any(not isinstance(r, dict) or id_field not in r for r in rows):
            raise Incomplete("Missing object IDs in enumeration.")
        ids = [object_id(r[id_field]) for r in rows]
        if len(set(ids)) != len(ids):
            raise Incomplete("Invalid or duplicated object IDs in enumeration.")
        return sorted(rows, key=lambda r: int(r[id_field]))

    def scope_inventory(self, used=False):
        params = {"selectParentTemplates": ["templateid"]} if used else {}
        rows = self.enumerate("template", "templateid", output=["templateid", "host"], **params)
        if any(not isinstance(row.get("host"), str) or not row["host"] for row in rows):
            raise Incomplete("Malformed template inventory.")
        return rows

    def scope_graph(self):
        templates = self.scope_inventory(True)
        hosts = self.enumerate("host", "hostid", output=["hostid"], selectParentTemplates=["templateid"])
        prototypes = self.enumerate("hostprototype", "hostid", output=["hostid"],
                                    selectParentHost=["hostid"], selectTemplates=["templateid"])
        actions = self.enumerate("action", "actionid", output=["actionid"],
                                 selectOperations=["operationtype", "optemplate"],
                                 selectRecoveryOperations=["operationtype"], selectUpdateOperations=["operationtype"])
        return relations(templates, hosts, prototypes, actions)

    def preview_scope(self, scope):
        scope = template_scope(scope)
        try:
            # The verified reads below probe exactly the preview's required methods.
            if scope["mode"] == "used":
                graph = self.scope_graph()
                _, metadata = resolve(graph, scope["include_templateids"])
                if graph != self.scope_graph():
                    raise Incomplete("Template inventory or relations changed during scope calculation.")
            else:
                rows = self.scope_inventory()
                if rows != self.scope_inventory():
                    raise Incomplete("Template inventory changed during scope calculation.")
                metadata = {"mode": "all", "total": len(rows), "selected": len(rows), "excluded": 0,
                            "manual_included": 0, "missing_manual_templateids": []}
            return public_summary(metadata)
        except (KeyError, TypeError, ValueError, AttributeError):
            raise StructureError("Unrecognized template scope API structure; snapshot stopped.") from None

    def verify_native(self, host_id: str, obj: dict, data: dict) -> None:
        scope = {"hostids": [host_id], "inherited": False}
        items = self.enumerate("item", "itemid", output=["itemid", "key_"], filter={"flags": 0}, **scope)
        # Native export includes configured LLD rules, not rules generated by nested discovery.
        discoveries = self.enumerate("discoveryrule", "itemid", output=["itemid", "key_"],
                                     filter={"flags": 1}, **scope)
        if self.series in ("7.4", "8.0"):
            discoveries += self.enumerate("discoveryruleprototype", "itemid", output=["itemid", "key_"],
                                          filter={"flags": 3}, **scope)
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

    def native(self, category: str, stage: Path, excluded: set, progress=None, selected=None, inventory=None) -> int:
        family, method, id_field = ("hosts", "host", "hostid") if category == "hosts" else (
            "templates", "template", "templateid")
        rows = inventory if inventory is not None else self.enumerate(method, id_field, output=[id_field, "host"])
        inventory = rows
        if selected is not None:
            if selected - {r[id_field] for r in rows}:
                raise Incomplete("Selected templates disappeared before native export.")
            rows = [r for r in rows if r[id_field] in selected]
        if progress:
            progress(category, "Running", {"processed": 0, "total": len(rows)})
        groups = (("hostgroup", "groupid", "host_groups"),) if category == "hosts" else (
            ("templategroup", "groupid", "template_groups"),)
        group_count = 0
        for group_method, group_id, group_key in groups:
            objects = self.enumerate(group_method, group_id, output=[group_id, "name", "uuid"])
            data = {"version": self.series, group_key: [{k: r[k] for k in ("name", "uuid")} for r in objects]}
            self.write(stage / category / f"{group_key}.json", sanitize(data, "export", excluded))
            group_count += len(objects)
        # One native object per file gives stable names and bounded export batches.
        for processed, row in enumerate(rows, 1):
            raw = self.call("configuration.export", format="json", options={family: [row[id_field]]})
            try:
                data = json.loads(raw)["zabbix_export"]
            except (ValueError, KeyError, TypeError):
                raise StructureError("Malformed native export response; snapshot stopped.") from None
            if not isinstance(data, dict) or data.get("version") != self.series:
                raise StructureError("Native export schema version does not match the connected Zabbix series.")
            returned = data.get(family, [])
            if len(returned) != 1 or returned[0].get("host" if category == "hosts" else "template") != row["host"]:
                raise Incomplete("Native export did not include the requested object.")
            self.verify_native(row[id_field], returned[0], data)
            self.write(stage / category / f"{row[id_field]}.json", sanitize(data, "export", excluded))
            if progress:
                progress(category, "Running", {"processed": processed, "total": len(rows),
                                               "excluded_fields": sorted(excluded)})
        again = self.enumerate(method, id_field, output=[id_field, "host"])
        if [(r[id_field], r["host"]) for r in inventory] != [(r[id_field], r["host"]) for r in again]:
            raise Incomplete("Object enumeration changed during native export.")
        return len(rows) + group_count

    def collected(self, category: str, stage: Path, excluded: set) -> int:
        if category == "actions":
            collectors = [("action", "actionid", "action", {"output": "extend", "selectFilter": "extend",
                           "selectOperations": "extend", "selectRecoveryOperations": "extend",
                           "selectUpdateOperations": "extend"})]
        elif category == "media_types":
            collectors = [("mediatype", "mediatypeid", "media", {
                "output": "extend", "selectMessageTemplates": "extend"})]
        else:
            collectors = [
                ("user", "userid", "user", {"output": ["userid", "username", "name", "surname", "roleid",
                 "autologin", "autologout", "lang", "refresh", "theme", "rows_per_page", "userdirectoryid", "url"],
                 "selectUsrgrps": ["usrgrpid"], "selectMedias": ["mediaid", "mediatypeid", "active", "severity", "period", "sendto"]}),
                ("usergroup", "usrgrpid", "user_group", {"output": ["usrgrpid", "name", "gui_access", "users_status",
                 "debug_mode", "userdirectoryid", "mfa_status", "mfaid"], "selectHostGroupRights": ["id", "permission"],
                 "selectTemplateGroupRights": ["id", "permission"], "selectTagFilters": ["groupid", "tag", "value"]}),
                ("role", "roleid", "role", {"output": ["roleid", "name", "type", "readonly"], "selectRules": "extend"})
            ]
        if self.series == "8.0" and category == "users":
            collectors[0][3]["output"].append("default_maintenance_period")
            collectors[1][3]["output"] += ["proxy_mode", "proxy_group_mode"]
            collectors[1][3].update(selectProxies=["proxyid"], selectProxyGroups=["proxy_groupid"],
                                    selectApmFilters=["key", "value", "signal_type"])
        total = 0
        for method, id_field, context, params in collectors:
            rows = self.enumerate(method, id_field, **params)
            for row in rows:
                self.write(stage / category / method / f"{row[id_field]}.json", sanitize(row, context, excluded))
            total += len(rows)
        if category == "users":
            excluded.add("/users/passwords_tokens_mfa_secrets")
        return total

    @staticmethod
    def write(path: Path, value) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(encode(value), encoding="utf-8", newline="\n")

    def export(self, categories: list[str], stage: Path, progress=None, scope=None) -> dict:
        coverage = {}
        graph = None
        selected = None
        scope = template_scope(scope)
        for category in categories:
            excluded = set()
            if progress:
                progress(category, "Running", {})
            try:
                if category == "templates" and scope["mode"] == "used":
                    graph = self.scope_graph()
                    selected, metadata = resolve(graph, scope["include_templateids"])
                if category in ("hosts", "templates"):
                    inventory = None
                    if category == "templates" and graph is not None:
                        inventory = [{"templateid": i, "host": name} for i, name in graph["inventory"]]
                    count = self.native(category, stage, excluded, progress,
                                        selected if category == "templates" else None, inventory)
                else:
                    count = self.collected(category, stage, excluded)
                coverage[category] = {"objects": count, "enumeration": "verified", "state": "exported",
                                      "excluded_fields": sorted(excluded)}
                if category == "templates" and graph is not None:
                    coverage[category]["template_scope"] = metadata
                    coverage[category]["template_groups"] = count - len(selected)
            except Exception as exc:
                if progress:
                    progress(category, "Incomplete" if isinstance(exc, Incomplete) else "Failed", {})
                if isinstance(exc, PolicyError):
                    raise PolicyError(f"Cannot export {category} (Zabbix {self.series}): {exc}") from None
                if isinstance(exc, Error):
                    raise
                if isinstance(exc, ProcessingError):
                    raise Transient(f"Zabbix transport failed while exporting {category}; retry later.") from None
                if isinstance(exc, (KeyError, TypeError, ValueError, AttributeError)):
                    raise StructureError(f"Unrecognized API/export structure in {category}; snapshot stopped. "
                                         "Check the worker journal and update the reviewed export policy.") from None
                if isinstance(exc, OSError):
                    raise
                raise Error(f"Unexpected export failure in {category}; see the worker journal for the failure location.") from None
            if progress:
                safe = dict(coverage[category])
                if "template_scope" in safe:
                    safe["template_scope"] = public_summary(safe["template_scope"])
                progress(category, "Exported", safe)
        if graph is not None:
            try:
                if graph != self.scope_graph():
                    raise Incomplete("Template inventory or relations changed during export; snapshot not published.")
            except Error as exc:
                if progress:
                    progress("templates", "Incomplete" if isinstance(exc, Incomplete) else "Failed", {})
                raise
        checksums = {str(p.relative_to(stage)).replace("\\", "/"): hashlib.sha256(p.read_bytes()).hexdigest()
                     for p in sorted(stage.rglob("*.json"))}
        manifest = {"schema": 2, "gitkeeper_version": __version__, "zabbix_version": self.version,
                    "coverage": coverage, "checksums": checksums, "restore_ready": False,
                    "guarantee": "Complete enumeration of selected scope; readable configuration and credentials preserved. No full recovery."}
        self.write(stage / "manifest.json", manifest)
        return coverage
