# Configuration tracking coverage

**Neither Zabbix 7.0 nor 7.4 is certified.** Partial read-only collector checks passed on Zabbix 7.4.15
for hosts/groups, actions, media types and users/groups/roles. Full template export and the complete
frontend-to-GitHub acceptance sequence remain unverified. See [live evidence](validation.md).
The implementation uses these version-specific official methods/properties, with synthetic fixtures
for both target series. `Exported` describes the readable scope below, never complete recovery.
The UI enables only these implemented collectors; other README categories have no exporter or checkbox.

| Category / methods | 7.0 implementation / tests | 7.4 implementation / tests | Readable scope | Excluded / not readable | Read limits / completeness | Import status |
| --- | --- | --- | --- | --- | --- | --- |
| Hosts, host groups: `host.get`, `hostgroup.get`, `configuration.export` | Implemented; mock fixtures; live not run | Implemented; mock fixtures; live not run | All enumerated host/group identities, interfaces, links, structural settings; native user-managed items and discovery | Inventory, web scenarios, descriptions, text/secret/vault macro values, SNMP/HTTP/IPMI credentials, arbitrary payloads, item keys and expressions | Super admin required; unbounded getters with counts/IDs; one host per native call; group JSON generated from explicit fields | No restore implemented; sanitized representation needs secret/payload reconstruction |
| Templates, template groups: `template.get`, `templategroup.get`, `configuration.export` | Implemented; mock fixtures; live not run | Implemented; mock fixtures include `wizard_ready`, omitted `readme`/macro `config`; live not run | Enumerated templates/groups, vendor, links, user-managed items/triggers/LLD and native prototypes/graphs/value-map identities | Same payload omissions as hosts; template dashboards, value-map mappings, LLD overrides and arbitrary README/macro configuration omitted | Whole enumeration checked before/after; inherited monitoring is represented through template links, not duplicated as independent user-managed items | Native API import is possible for original native exports; these redacted files are not directly importable |
| Items, triggers, LLD: native export plus `item.get`, `trigger.get`, `discoveryrule.get`, `itemprototype.get`, `triggerprototype.get` | Implemented; mock missing-item test and ordering tests; live not run | Same implementation; mock fixtures; live not run | Names/UUIDs, types, status, schedules, priority, dependency names, preprocessing types/order, native graph/host prototypes | Keys, expressions, preprocessing arguments/scripts/error payloads, HTTP/SSH/SNMP credentials, arbitrary tag values, overrides | Non-inherited normal items/triggers, LLD keys and item/trigger prototype identities compared with native content; graph/host prototypes not independently counted; same-count concurrent edits remain possible | No API restore implemented; original keys/expressions/payloads must be supplied |
| Actions: `action.get` with `selectFilter`, `selectOperations`, `selectRecoveryOperations`, `selectUpdateOperations` | Implemented; mock nested redaction and diff tests; live not run | Implemented with shared policy; mock fixtures; live not run | IDs, names, source/status/escalation settings, condition types/operators, operation types/steps, recipient and script references | Condition values/formulas, notification subject/body, arbitrary tags omitted; referenced custom scripts themselves not collected | Super admin and method access; counts/unique IDs checked; missing/unknown structures fail closed | No restore; source IDs are instance-local references |
| Media types: `mediatype.get` with explicit output and `selectMessageTemplates` | Implemented; mock redaction test; live not run | Implemented; mock fixtures; live not run | IDs, names, type/status, transport flags, retry/concurrency/timeout, message event/recovery type | Credentials, server/endpoint fields, sender addresses, executables, webhook scripts/parameters, menu URLs and notification texts omitted/not requested | Super admin; count-before/after; no raw response committed; this is metadata tracking, not full media-type backup | No restore; substantial payload reconstruction needed |
| Users: `user.get` with explicit output, `selectUsrgrps`, `selectMedias` | Implemented; mock fixtures; live not run | Implemented; mock fixtures; live not run | User identity/name, role and group IDs, UI settings, directory ID, media type/severity/period/active flags | Password hashes, API tokens, MFA secrets and media addresses never requested; arbitrary URL omitted | Super admin probe prevents filtered account visibility; count/unique IDs checked | No restore; passwords/tokens and recipient addresses require regeneration/re-entry |
| User groups: `usergroup.get`, `selectHostGroupRights`, `selectTemplateGroupRights`, `selectTagFilters` | Implemented; mock fixtures; live not run | Implemented; mock fixtures; live not run | Group identities, status/access/MFA flags, rights, tag names | Arbitrary tag-filter values omitted | Count/unique IDs checked; external directory/MFA configuration not collected | No restore; IDs require dependency resolution |
| Roles: `role.get` with `selectRules` | Implemented; mock fixtures; live not run | Implemented; mock fixtures; live not run | Role name/type/readonly, UI/action/module/service references, access flags | Arbitrary API-list and service-tag values omitted conservatively | Super admin; count/unique IDs checked; unknown rules reject snapshot | No restore; module/service IDs are instance-local |
| Remaining README categories: discovery/automation, infrastructure, operations, administration | Not implemented; no tests/checkbox | Not implemented; no tests/checkbox | None (template-local native LLD is covered above) | Entire categories unavailable | No claim of complete instance configuration | Future coverage/API restore milestones |
| Database/local-file disaster recovery | Not implemented; no tests | Not implemented; no tests | None | Credentials and state unavailable through API remain absent | No backup/recovery package exists | Future encrypted full-recovery milestone; requires clean-instance round-trip |

The versioned `gitkeeper/manifest.json` records schema, actual Zabbix patch version, GitKeeper version,
selected categories, object totals, explicit excluded field paths and checksums. Run timestamps remain
in operational state/commit metadata. Changes only in omitted values are intentionally invisible to Git.
Object totals count primary host/template objects plus their groups, and all dedicated collector
objects; they are not a sum of every nested monitoring object. Failed/incomplete categories cannot
publish a snapshot or update `last_success`.

On 7.4, configured discovery prototypes are independently enumerated through `discoveryruleprototype.get`
and compared with the native discovery-rule list. Generated LLD rules (flags 5) and generated discovery
prototypes (flags 7) are runtime results, excluded from this configuration-only comparison; configured
roots (flags 1) and prototypes (flags 3) must still match. Their child item/trigger prototypes are checked
using the same native coverage checks. Graph/host prototypes are not independently counted.

Host-prototype `group_links` use the native nested form `[{"group": {"name": "..."}}]` in both
[7.0](https://github.com/zabbix/zabbix/blob/release/7.0/ui/include/classes/import/validators/C70XmlValidator.php)
and [7.4](https://github.com/zabbix/zabbix/blob/7.4.15/ui/include/classes/import/validators/C74XmlValidator.php).
The shared policy preserves this structure and group-name metadata. Unknown fields still stop the
snapshot; the public error identifies the category, reviewed Zabbix series and containing schema path,
without echoing unknown field names or API values. This indicates a GitKeeper policy gap rather than
asking the administrator to change their Zabbix configuration.

Update intervals and user-media periods preserve semicolon-separated schedules, as documented for
[item intervals](https://www.zabbix.com/documentation/7.4/en/manual/api/reference/item/object) and
[time periods](https://www.zabbix.com/documentation/7.4/en/manual/appendix/time_period).
Ordinary durations and context-free macro references remain tracked. Control/time values containing
[contextual user macros](https://www.zabbix.com/documentation/7.4/en/manual/config/macros/user_macros_context)
are omitted in full and listed in `excluded_fields`, since those contexts can contain arbitrary text.
Quoted, unquoted and escaped contexts do not stop an export merely because of their punctuation.
This applies to the common 7.0/7.4 policy, including discovery rules and item prototypes.
Unsupported scalar syntax/type errors report the category and reviewed schema path without the value.

The matrix above describes the original synthetic-fixture baseline; the partial live results are
recorded separately in [validation](validation.md), without certifying an entire series. The policy
was reviewed against official 7.0/7.4 API and exporter sources. Future support declarations require
fixtures from a disposable real
instance, expected-object comparisons and the full frontend → OAuth → commit acceptance sequence.
