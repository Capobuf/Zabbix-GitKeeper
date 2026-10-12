"""Explicit context-based policy shared by reviewed 7.0 and 7.4 schemas.

Identity/label fields are confidential metadata. Free-form payloads are omitted,
including ordinary macro values: a text macro can hold a password too.
"""

import json
import re

from .storage import Error

OMITTED = "<omitted: potentially sensitive>"
UNREADABLE = "<not-readable: secret or vault macro>"


class PolicyError(Error):
    """Unsupported structure, described only with reviewed schema paths."""


def rule(identity="", scalar="", redact="", children=None):
    return {**{k: "identity" for k in identity.split()}, **{k: "scalar" for k in scalar.split()},
            **{k: "redact" for k in redact.split()}, **(children or {})}


ITEM = rule("uuid name interface_ref", "type value_type history trends status discover inventory_link "
            "authtype timeout post_type status_codes follow_redirects retrieve_mode request_method "
            "output_format allow_traps verify_peer verify_host lifetime lifetime_type enabled_lifetime "
            "enabled_lifetime_type", "key snmp_oid allowed_hosts params ipmi_sensor username password "
            "publickey privatekey description jmx_endpoint url query_fields parameters posts http_proxy "
            "headers ssl_cert_file ssl_key_file ssl_key_password logtimefmt units parent_discovery_rule",
            {"tags": "tags", "preprocessing": "preprocessing", "valuemap": "reference", "master_item": "master",
             "triggers": "trigger", "trigger_prototypes": "trigger", "delay": "schedule"})
TRIGGER = rule("uuid name", "recovery_mode correlation_mode type status priority manual_close discover",
               "expression recovery_expression correlation_tag description event_name opdata url url_name",
               {"tags": "tags", "dependencies": "dependency"})
HOST = rule("uuid host name", "status monitored_by ipmi_authtype ipmi_privilege inventory_mode custom_interfaces discover",
            "description ipmi_username ipmi_password inventory httptests dashboards",
            {"templates": "reference", "groups": "reference", "interfaces": "interface", "items": "item",
             "discovery_rules": "discovery", "macros": "macro", "tags": "tags", "valuemaps": "valuemap",
             "proxy": "reference", "proxy_group": "reference", "group_links": "group_link",
             "group_prototypes": "reference"})
SCHEMAS = {
    "export": rule("version", "", "date", {"host_groups": "group", "template_groups": "group",
                    "hosts": "host", "templates": "template", "triggers": "trigger", "graphs": "graph"}),
    "group": rule("uuid name"), "reference": rule("name"),
    "group_link": rule(children={"group": "reference"}), "master": rule("", "", "key"),
    "host": HOST, "template": {**HOST, "template": "identity", "vendor": "vendor",
                                "wizard_ready": "scalar", "readme": "redact"},
    "vendor": rule("name version"), "item": ITEM,
    "discovery": {**ITEM, "filter": "native_filter", "item_prototypes": "item", "trigger_prototypes": "trigger",
                  "graph_prototypes": "graph", "host_prototypes": "host", "lld_macro_paths": "redact",
                  "overrides": "redact"},
    "interface": rule("interface_ref ip dns", "default type useip port", "", {"details": "snmp"}),
    "snmp": rule("", "version bulk securitylevel authprotocol privprotocol max_repetitions",
                 "community contextname securityname authpassphrase privpassphrase"),
    "macro": rule("macro", "type", "value description config"),
    "tags": rule("tag", "operator", "value"),
    "preprocessing": rule("", "type error_handler", "parameters error_handler_params"),
    "valuemap": rule("uuid name", "", "mappings"),
    "trigger": TRIGGER, "dependency": rule("name", "", "expression recovery_expression"),
    "native_filter": rule("", "evaltype", "formula", {"conditions": "native_condition"}),
    "native_condition": rule("macro formulaid", "operator", "value"),
    "graph": rule("uuid name", "width height yaxismin yaxismax show_work_period show_triggers type show_legend "
                  "show_3d percent_left percent_right ymin_type_1 ymax_type_1 discover", "",
                  {"graph_items": "graph_item", "ymin_item_1": "graph_ref", "ymax_item_1": "graph_ref"}),
    "graph_ref": rule("host", "", "key"),
    "graph_item": rule("color", "sortorder drawtype yaxisside calc_fnc type", "", {"item": "graph_ref"}),
    "action": rule("actionid name", "eventsource status esc_period pause_suppressed notify_if_canceled pause_symptoms",
                   "", {"filter": "action_filter", "operations": "operation", "recovery_operations": "operation",
                        "update_operations": "operation"}),
    "action_filter": rule("", "evaltype", "formula eval_formula", {"conditions": "action_condition"}),
    "action_condition": rule("formulaid", "conditiontype operator", "value value2"),
    "operation": rule("operationid actionid", "operationtype esc_period esc_step_from esc_step_to evaltype recovery",
                      "", {"opmessage": "message", "opmessage_grp": "recipient", "opmessage_usr": "recipient",
                           "opcommand": "command", "opcommand_hst": "target", "opcommand_grp": "target",
                           "opgroup": "target", "optemplate": "target", "opinventory": "opinventory",
                           "opconditions": "opcondition", "optag": "tags"}),
    "message": rule("mediatypeid", "default_msg", "subject message"),
    "recipient": rule("usrgrpid userid operationid"),
    "target": rule("hostid groupid templateid operationid opcommand_hstid opcommand_grpid"),
    "command": rule("scriptid"), "opinventory": rule("", "inventory_mode"),
    "opcondition": rule("opconditionid operationid", "conditiontype operator", "value"),
    "media": rule("mediatypeid name", "type status smtp_port smtp_security smtp_verify_peer smtp_verify_host "
                  "smtp_authentication maxsessions maxattempts attempt_interval message_format timeout "
                  "process_tags show_event_menu provider", "smtp_server smtp_helo smtp_email exec_path gsm_modem "
                  "username passwd script parameters event_menu_url event_menu_name description",
                  {"message_templates": "message_template"}),
    "message_template": rule("", "eventsource recovery", "subject message"),
    "user": rule("userid username name surname roleid", "autologin autologout lang refresh theme rows_per_page "
                 "userdirectoryid ts_provisioned", "url", {"usrgrps": "user_group_ref", "medias": "user_media"}),
    "user_group_ref": rule("usrgrpid"),
    "user_media": rule("mediaid mediatypeid", "active severity", "sendto", {"period": "schedule"}),
    "user_group": rule("usrgrpid name", "gui_access users_status debug_mode userdirectoryid mfa_status mfaid",
                       "", {"hostgroup_rights": "right", "templategroup_rights": "right", "tag_filters": "tag_filter"}),
    "right": rule("id", "permission"), "tag_filter": rule("groupid tag", "", "value"),
    "role": rule("roleid name", "type readonly", "", {"rules": "role_rules"}),
    "role_rules": rule("", "ui.default_access services.read.mode services.write.mode modules.default_access "
                       "api.access api.mode actions.default_access", "api services.read.tag services.write.tag",
                       {"ui": "role_access", "actions": "role_access", "modules": "module_access",
                        "services.read.list": "service_ref", "services.write.list": "service_ref"}),
    "role_access": rule("name", "status"), "module_access": rule("moduleid", "status"),
    "service_ref": rule("serviceid")
}
# Only these lists are semantic sets. Other arrays preserve execution order.
SETS = {"host_groups", "template_groups", "hosts", "templates", "groups", "items", "discovery_rules", "tags",
        "macros", "valuemaps", "triggers", "graphs", "dependencies", "item_prototypes", "trigger_prototypes",
        "graph_prototypes", "host_prototypes", "usrgrps", "medias", "hostgroup_rights", "templategroup_rights",
        "tag_filters", "opmessage_grp", "opmessage_usr", "opcommand_hst", "opcommand_grp", "opgroup", "optemplate"}


def encode(value) -> str:
    return json.dumps(value, sort_keys=True, ensure_ascii=False, indent=2, allow_nan=False) + "\n"


def sanitize(value, context: str, excluded: set[str], path: str = "", collection: str = ""):
    if value == "" and context in SCHEMAS:
        return ""
    if isinstance(value, list):
        result = [sanitize(v, context, excluded, path + "[]") for v in value]
        return sorted(result, key=encode) if collection in SETS else result
    if not isinstance(value, dict) or context not in SCHEMAS:
        raise Error("Export field shape is not covered by the serialization policy.")
    result = {}
    schema = SCHEMAS[context]
    if set(value) - set(schema):
        # Do not reflect arbitrary field names or API values into public errors.
        raise PolicyError(f"Unsupported fields in {path or '/'} (schema: {context}); snapshot stopped. "
                          "GitKeeper's export policy must be updated for this structure.")
    for key, item in value.items():
        field_path = path + "/" + key
        policy = schema[key]
        if policy == "redact":
            excluded.add(field_path)
            result[key] = UNREADABLE if context == "macro" and key == "value" and str(value.get("type")) in (
                "1", "2", "SECRET_TEXT", "VAULT") else OMITTED
        elif policy in ("identity", "scalar", "schedule"):
            if not isinstance(item, (str, int, float, bool)) or item is None:
                raise PolicyError(f"Unexpected scalar type at {field_path} (schema: {context}); snapshot stopped.")
            if policy != "identity":
                # Contexts are arbitrary text, even when referenced by control/time fields.
                # Omit the entire value rather than publish a potentially sensitive context.
                if re.search(r"\{\$[A-Za-z0-9_.]+:", str(item)):
                    excluded.add(field_path)
                    result[key] = OMITTED
                    continue
                pattern = r"[A-Za-z0-9_.,+{}#$:;/ -]*" if policy == "schedule" else r"[A-Za-z0-9_.,+{}#$:/ -]*"
                if not re.fullmatch(pattern, str(item)):
                    raise PolicyError(f"Unsupported control or scheduling value at {field_path} "
                                      f"(schema: {context}); snapshot stopped. GitKeeper's export policy "
                                      "must be updated for this syntax.")
            result[key] = item
        else:
            result[key] = sanitize(item, policy, excluded, field_path, key)
    return result
