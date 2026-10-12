"""Template-only dependency closure over verified, bulk API inventories."""

import re

from .storage import Error


class Incomplete(Error):
    pass


def object_id(value):
    if not isinstance(value, str) or not re.fullmatch(r"[1-9][0-9]{0,19}", value):
        raise Incomplete("Invalid object ID in template scope relations.")
    return value


def references(row, key):
    values = row.get(key)
    if not isinstance(values, list) or any(not isinstance(value, dict) for value in values):
        raise Incomplete("Missing or malformed template scope relation.")
    return tuple(sorted({object_id(value.get("templateid")) for value in values}, key=int))


def relations(templates, hosts, prototypes, actions):
    """Retain only IDs, names and decisive edges; never API message/macro bodies."""
    parents = {row["templateid"]: references(row, "parentTemplates") for row in templates}
    host_links = {row["hostid"]: references(row, "parentTemplates") for row in hosts}
    prototype_links = {}
    for row in prototypes:
        parent = row.get("parentHost")
        if not isinstance(parent, dict):
            raise Incomplete("Missing or malformed host prototype parent.")
        parent_id = object_id(parent.get("hostid"))
        if parent_id not in parents and parent_id not in host_links:
            raise Incomplete("Host prototype parent is absent from verified inventory.")
        prototype_links[row["hostid"]] = (parent_id, references(row, "templates"))
    action_links = {}
    for row in actions:
        edges = set()
        for key in ("operations", "recovery_operations", "update_operations"):
            operations = row.get(key)
            if not isinstance(operations, list):
                raise Incomplete("Missing or malformed action operations.")
            for operation in operations:
                if not isinstance(operation, dict):
                    raise Incomplete("Malformed action operation.")
                kind = operation.get("operationtype")
                allowed = {str(i) for i in range(15)}  # Reviewed constants in both 7.0 and 7.4.
                if str(kind) not in allowed or isinstance(kind, bool):
                    raise Incomplete("Unsupported action operation in template scope.")
                if str(kind) in ("6", "7"):
                    linked = references(operation, "optemplate")
                    if key != "operations" or not linked:
                        raise Incomplete("Missing or malformed action template references.")
                    edges.update(linked)
                elif "optemplate" in operation:
                    raise Incomplete("Unexpected action template references.")
        action_links[row["actionid"]] = tuple(sorted(edges, key=int))
    return {"inventory": tuple((r["templateid"], r["host"]) for r in templates),
            "parents": parents, "hosts": host_links, "prototypes": prototype_links, "actions": action_links}


def resolve(graph, manual):
    inventory = set(graph["parents"])
    edges = set()
    for linked in (*graph["parents"].values(), *graph["hosts"].values(), *graph["actions"].values()):
        edges.update(linked)
    for _, linked in graph["prototypes"].values():
        edges.update(linked)
    if edges - inventory:
        raise Incomplete("An automatic template reference is absent from verified inventory.")
    included = inventory.intersection(manual)
    for edges in (*graph["hosts"].values(), *graph["actions"].values()):
        included.update(edges)
    expanded = set()
    active_prototypes = set()
    while True:
        if included - inventory:
            raise Incomplete("An automatic template reference is absent from verified inventory.")
        pending = included - expanded
        for template_id in pending:
            included.update(graph["parents"][template_id])
        expanded.update(pending)
        for prototype_id, (parent_id, edges) in graph["prototypes"].items():
            if prototype_id not in active_prototypes and (parent_id in graph["hosts"] or parent_id in included):
                included.update(edges)
                active_prototypes.add(prototype_id)
        if included == expanded:
            break
    missing = sorted(set(manual) - inventory, key=int)
    return included, {"mode": "used", "total": len(inventory), "selected": len(included),
                      "excluded": len(inventory - included), "manual_included": len(inventory.intersection(manual)),
                      "missing_manual_templateids": missing}


def public_summary(metadata):
    return {key: metadata[key] for key in ("mode", "total", "selected", "excluded", "manual_included")} | {
        "missing_manual": len(metadata["missing_manual_templateids"]),
        "warning": "Manually included templates no longer present in verified inventory were ignored."
        if metadata["missing_manual_templateids"] else None}
