# Technical Notes

## FreePBX Integration

`failovercontrol_destinations()`, `failovercontrol_getdest()` and `failovercontrol_getdestinfo()` register stable `app-failovercontrol,<uuid>,1` destinations through FreePBX's provider API. Asterisk extensions are added through the Core `$ext` `extensions` object.

Core 17's `functions.inc.php` creates `ext-trunk` entries from enabled trunks and creates its `macro-dialout-trunk` context when an outbound route requires that dial macro. The macro accepts a trunk ID and resolves trunk-specific Core globals; the primary trunk itself does not need to be attached to a patterned outbound route. The module calls Core's `dialout-trunk` macro with the primary ID, validated `FROM_DID` and continue-on-failure argument. This preserves Core trunk configuration, number rules, caller-ID policy, dial options, channel controls and SIP-header hooks. If the shared Core macro is not generated at all, no supported supervised primary path is available and the module cannot place the attempt.

The primary remains enabled PJSIP only. A nonzero ring limit sets `TRUNK_RING_TIMER` for the whole attempt; it is not a separate connection-establishment timeout. Zero leaves the existing Core timer unchanged. The control does not set caller ID; Core's configured caller-ID policy still applies. `DIALSTATUS` and the hangup-cause list are cleared before the attempt, so a Core channel-limit or predial-hook return without `Dial()` cannot reuse an earlier call leg's result. `ANSWER` exits before failure classification, and `CANCEL` does not match a failover branch. Unselected outcomes go to the primary result handlers rather than authorising a fallback.

## Failover Targets

The UK number target calls `Local/<national-number>@outrt-<id>/n` to preserve Core route/trunk handling. Validation checks matching patterns, enabled trunks and destination-preserving route/trunk dial rules; password, time-group, emergency and chained-destination routes are rejected.

An enabled non-ENUM Core trunk target transfers to `ext-trunk,<id>,1`. A Miscellaneous Destination target transfers to `ext-miscdests,<id>,1`, whose native dialplan executes the saved `destdial` through `from-internal`. Neither native transfer is supervised again by this module. The destination manager's `failovercontrol_check_destinations()` callback reports native targets as dependencies. Control deletion and module disable/uninstall continue to use `FreePBX::Destinations()->getAllInUseDestinations()`.

The primary trunk cannot also be its own trunk failover destination. Numeric local inbound DIDs are rejected for UK number and Miscellaneous Destination targets. The inherited `__FC_ACTIVE` guard blocks local recursive re-entry. This is not a distributed loop-prevention protocol across remote PBXs/SBCs. A missing or changed Miscellaneous Destination is not emitted as a fallback and is reported as a configuration error; the Miscellaneous Destinations module itself may not prevent deletion based on destination references.

## Failure Policy And Generated Dialplan

`FailoverPolicy::selectedFailureConditions()` feeds the PHP classifier, dialplan generator and status verifier. The conditions are independent:

| Result | Unavailable | Busy | No Answer |
| --- | --- | --- | --- |
| SIP 503/504 or accepted transport/status cause | Qualifies | — | — |
| SIP 408 / cause 18 with no observed ringing | Qualifies | — | If `DIALSTATUS=NOANSWER`, also qualifies |
| SIP 408 / cause 18 after observed ringing | Does not qualify as unavailable | — | Qualifies only when selected and `DIALSTATUS=NOANSWER` |
| Ordinary `NOANSWER` (commonly cause 19) | Does not qualify | — | Qualifies only when selected |
| `BUSY` | Does not qualify | Qualifies only when selected | Does not qualify |
| `ANSWER`, `CANCEL`, unknown/rejected causes | Does not qualify | Does not qualify | Does not qualify |

Unavailable cause pairs are `NOANSWER/18` only without ringing, `CHANUNAVAIL/20,38,41`, and `CONGESTION/38,41,42,102`. SIP 408, 503 and 504 are considered only for `CHANUNAVAIL`, `CONGESTION` and `NOANSWER`. The key list from `HANGUPCAUSE_KEYS()` is stored in `FC_PEERS`, then `CUT(FC_PEERS,\,,1)` selects its first channel. `CUT()` takes a variable name, not the expanded list. `HANGUPCAUSE(channel,tech)` reads that channel's technology cause when available. This assumes one primary dialled channel; confirm the selected cause on the target Core/PJSIP build.

An observed SIP 408 after ringing takes the dedicated timeout-after-ringing branch; numeric cause codes do not override it. Numeric cause 34 alone remains excluded because it is ambiguous. Asterisk mappings vary by driver and signalling path; the allowlist needs live confirmation on the target build. Explicit SIP rejection is not an independent selected condition; an ordinary `NOANSWER` or `BUSY` result is still governed by its own setting.

When Unavailable and No Answer are both selected, the unavailable classifier can route `NOANSWER` through its cause checks. If SIP 408 follows ringing, the generated timeout branch defers to the selected No Answer condition rather than suppressing it. Without No Answer, it returns to primary failure handling. If Unavailable is off, the No Answer branch independently handles `NOANSWER`; Busy is handled by its own branch. With failover disabled or no conditions selected, only the primary route is generated.

## Persistence And Verification

The PDO repository uses prepared statements and stable UUIDv4 identifiers. Schema migration adds `failover_type` (default `number`) and `failover_destination_id` without clearing existing numbers, routes or condition settings. MySQL/MariaDB migrations and indexes are idempotent; SQLite is used only for contract tests.

The development version is 0.2.0 in both `module.xml` and `FailoverPolicy::MODULE_VERSION`. The previous archive's 0.1.0 filename was not the implementation version.

The status verifier compares saved control fields and current Core trunk/route/target metadata with the fingerprint in the active, non-routing status extension. That fingerprint records the settings used during generation; it is not proof that the routing instructions remain intact. The verifier therefore also checks the active control's complete ordered instruction list, consecutive priorities and labels, including `test_timeout` and `test_timeout_after_ringing`. It accepts Asterisk application-name case differences, not changed arguments, extra calls or arbitrary routing. Disabled controls must contain the primary-only path with no number or native fallback.

It separately inspects the primary `ext-trunk`, `macro-dialout-trunk`, and selected number route or native destination, including the saved Miscellaneous Destination dial target. These checks are selected dependency evidence, not a reconstruction of every effective Core global, trunk option or external application's dialplan. The module does not independently read the generated configuration file on disk: a matching active fingerprint links generation to saved settings, and active instruction checks validate the loaded control.

It reports FreePBX destination references, but does not validate whether an Inbound Route currently targets this control. An unused control remains valid. The success labels are **Control Enabled** and **Control Disabled**; no status claims that an inbound DID, carrier or live call has been verified.

`Unknown` means Asterisk/AMI could not confirm the queried dialplan. A fingerprint mismatch means Reload Required; a missing/changed Core dependency or branch is reported as a configuration error or reload requirement, not as success.

## Security And Validation Boundary

UI mutations are session-CSRF protected and authenticated through FreePBX. Trunk IDs, native destination IDs, telephone numbers and route properties are validated server-side; database writes are parameterised. Calls use Core-managed trunk and route resources. The module does not set caller ID, modify trunks/routes/firewall settings, execute shell commands or expose an external management API.

The six PHP contracts test policy, persistence, schema migration, generated dialplan objects, status and lifecycle behaviour with SQLite and FreePBX/Asterisk doubles. Status fixtures come from `failovercontrol_get_config()`, with test rendering shaped after Asterisk 20's `dialplan show` output. The dialplan contract walks the actual generated failure branches across all eight condition combinations, eight statuses, seven technology-cause inputs, ten numeric-cause inputs and both ringing states (8,960 cases), comparing results with the PHP policy.

As an additional local check, the status contract has also passed with FreePBX 17 framework application renderers preloaded instead of the test renderers. The renderer source was inspected alongside Core 17 trunk generation and Asterisk 20 `CUT()`, `Dial()` and CLI output code. This is source-backed validation, not a live FreePBX installation or Asterisk parser test.

Installation and MariaDB upgrades, actual AMI output shape, `fwconsole` command discovery, `DIALSTATUS`/technology-cause mappings, ringing detection, caller hangup, native destination transfer and PSTN acceptance require an isolated FreePBX 17/Asterisk 20 system. Chargeable calls require operator approval.
