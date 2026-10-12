# Configuration tracking coverage

**Neither Zabbix 7.0 nor 7.4 is certified.** Read-only checks and complete collector execution with
publication passed on Zabbix 7.4.15 under the earlier redaction policy. The readable-credentials policy
also completed a live schema-1 to schema-2 migration and GitHub publication on 2026-10-12, with no legacy
omission markers in the current snapshot. Synthetic regression fixtures cover both target series.
Inventory/field coverage comparison and the complete fresh-configuration acceptance sequence remain
unverified. See [validation](validation.md).

Manifest schema 2 preserves readable configuration **including plaintext credentials** in the private
repository. Schema 1 snapshots used broad redaction. GitKeeper no longer substitutes
`<omitted: potentially sensitive>` for readable values. The next successful export replaces the current
managed snapshot; previous commits remain in Git history. No restore or full recovery is implemented.

| Category / official methods | Preserved configuration | Unavailable / outside the collector | Verification and restore limits |
| --- | --- | --- | --- |
| Hosts and host groups: `host.get`, `hostgroup.get`, `configuration.export` | Native host configuration, interfaces and readable credentials, template links, macros, inventory, tags, web scenarios and user-managed monitoring returned by native export; group names/UUIDs | Secret-text macro values missing/empty in native export; resolved Vault secrets; properties not returned by native export | Unbounded enumeration with counts/unique IDs; one host per native call; native monitoring compared with separate getters. No restore tool |
| Templates and template groups: `template.get`, `templategroup.get`, `configuration.export` | Native template configuration, vendor, links, README, macro configuration, dashboards, value-map mappings and monitoring returned by native export | Same native/API limitations as hosts | Enumeration checked before/after; inherited monitoring is represented through links. No restore tool |
| Items, triggers and LLD: native export plus `item.get`, `trigger.get`, `discoveryrule.get`, `itemprototype.get`, `triggerprototype.get` | Keys, expressions, dependencies, preprocessing parameters/scripts in execution order, credentials, HTTP payloads, discovery filters, paths, overrides, prototypes and graphs returned by native export | Properties absent from native export | Non-inherited normal items/triggers, configured LLD roots and item/trigger prototype identities checked. Graph/host prototypes not independently counted; same-count concurrent edits cannot be ruled out |
| Actions: `action.get` and filter/operation selectors | Names, status, conditions and values, formulas, messages, escalation settings, recipients, script references and operation tags | Custom scripts referenced by ID are not independently collected | Counts/unique IDs checked; missing/unknown structures fail closed. IDs are instance-local; no restore |
| Media types: `mediatype.get`, `selectMessageTemplates` | Transport settings, server/endpoint/sender fields, readable username/password, executable names, webhook scripts/parameters, menu settings, descriptions and notification texts | Executable file contents are not API configuration | Explicit output fields; counts/unique IDs checked. No restore |
| Users: `user.get`, `selectUsrgrps`, `selectMedias` | User identity/name, role/group IDs, selected UI settings, directory ID, URL, media destinations, severity, period and active flags | User passwords, API tokens and MFA secrets are not collected | Super admin probe; counts/unique IDs checked. No restore; unavailable credentials require re-entry |
| User groups: `usergroup.get` and rights/tag-filter selectors | Identities, access/MFA flags, rights, tag-filter names and values | External directory and MFA configuration not collected | Counts/unique IDs checked; instance-local references; no restore |
| Roles: `role.get`, `selectRules` | Names/types, UI/action/module/service permissions, API lists and service-tag rules | Referenced modules/services are not independently collected | Counts/unique IDs checked; unknown rules stop export; no restore |
| Remaining README categories | Not implemented; no checkbox/exporter | Entire categories unavailable | No claim of complete instance configuration |
| Database/local-file disaster recovery | Not implemented | Database state and local files unavailable through these collectors | No recovery package exists |

The manifest records schema, actual Zabbix patch version, GitKeeper version, selected categories,
primary-object totals, unavailable field paths and checksums. Native export timestamps are removed;
run timestamps remain in operational state/commit metadata. Changes in readable payloads and credentials
now produce Git diffs. Unchanged configuration produces no new commit. Failed/incomplete categories
cannot publish a snapshot or update `last_success`. Worker authentication tokens remain outside snapshots
and public status.

Missing/empty values of `SECRET_TEXT` macros are represented as `<not-readable: secret macro>` and
listed in `excluded_fields`. Text macro values, empty text values and Vault references are preserved.
Resolved Vault secrets are not available through native export. See the official
[secret macro documentation](https://www.zabbix.com/documentation/7.4/en/manual/config/macros/secret_macros).
Readable values returned by the API are preserved even when potentially sensitive.

The shared 7.0/7.4 policy retains reviewed structures and rejects unknown fields in those structures
without exposing field names or values in public errors. Free-form payload subtrees preserve all
returned fields and array order. Update intervals and user-media periods retain semicolon-separated
schedules, including contextual macro references; contexts are no longer redacted.

On 7.4, configured discovery prototypes are independently enumerated through `discoveryruleprototype.get`
and compared with the native discovery-rule list. Generated LLD rules (flags 5) and generated discovery
prototypes (flags 7) are runtime results and excluded from this comparison. Configured roots (flags 1)
and prototypes (flags 3), and their child item/trigger prototypes, must match.

Snapshots retain the existing native-shaped envelope and can contain unreadable markers. They must not
be fed directly to `configuration.import` without schema-aware preparation and dependency resolution.

References: official [7.0 media getter](https://www.zabbix.com/documentation/7.0/en/manual/api/reference/mediatype/get),
[7.4 media getter](https://www.zabbix.com/documentation/7.4/en/manual/api/reference/mediatype/get),
[7.4 native template export](https://www.zabbix.com/documentation/7.4/en/manual/xml_export_import/templates),
[7.4 export builder](https://github.com/zabbix/zabbix/blob/release/7.4/ui/include/classes/export/CConfigurationExportBuilder.php).
