# Failover Control 0.2.1 for FreePBX 17

> **Experimental:** Failover Control is under active development and is not suitable for production systems.

Failover Control (`failovercontrol`) provides named FreePBX destinations which supervise a call through an existing PJSIP primary trunk. When a selected failure condition qualifies, the call passes once to an alternative trunk, Miscellaneous Destination or UK telephone number.

For example: **Inbound Route → Failover Control → Customer PBX trunk**, with an alternative destination used when the customer PBX is unavailable.

## Release Status

Version 0.2.1 is a development milestone for further experimental testing. It is unsigned. Version 0.2.0 has been installed successfully on FreePBX 17 with Asterisk 22.10.1, and its administrative interface loads correctly. Live call handling still requires verification.

## Compatibility

The call-handling development target is FreePBX 17 with Asterisk 20 and a PJSIP primary trunk. Installation and administrative-interface loading have also been confirmed for 0.2.0 on Asterisk 22.10.1; this does not establish call-handling compatibility. FreePBX 16 has not been validated.

## Requirements

- FreePBX 17 with Core.
- Asterisk 20 with `chan_pjsip`, `func_hangupcause`, `func_cut` and `HangupCauseClear()`.
- PHP 8.1+ with PDO, the FreePBX MySQL/MariaDB connection and `mbstring`.
- Asterisk Manager access available to FreePBX for active configuration checks.
- An enabled existing PJSIP primary trunk.
- An eligible existing failover resource; Miscellaneous Destinations requires the `miscdests` module.

Core creates `ext-trunk` entries independently of outbound route membership. However, its supervised `macro-dialout-trunk` is generated when a Core outbound route needs it. The selected primary need not belong to any outbound route, but this shared macro must exist. The module reports a configuration error if it is absent; it does not create or alter routes to supply it.

## Installing

Place the reviewed module directory at `/var/www/html/admin/modules/failovercontrol/`, or the equivalent modules path configured on the test system. The directory must contain `module.xml`, not another nested module directory.

Run the FreePBX commands from a neutral directory:

```sh
cd ~
fwconsole ma install failovercontrol
fwconsole chown
fwconsole reload
```

Do not use `fwconsole ma installlocal` to install this individual module: that command operates on all local modules.

The module appears under **Applications → Failover Control**. Confirm that its destination provider is available in an Inbound Route's **Set Destination** selector before directing test traffic through it.

## Updating Failover Control

Back up the module files and FreePBX database, replace the files in the existing module directory with the reviewed version, then run the same installation, ownership and reload commands above. Do not uninstall during an update: uninstall removes the module's saved controls.

The schema upgrade preserves existing controls and defaults legacy failover destinations to the UK-number option. Check the installed version and read-back status afterwards:

```sh
fwconsole ma list | grep -i failovercontrol
fwconsole failovercontrol:status
```

## User Interface

Create a named control under **Applications → Failover Control**:

1. Select the existing primary PJSIP trunk.
2. Select the failover destination type and its existing resource, or enter a UK number and its outbound route.
3. Select the intended failure conditions: **Unavailable**, **Busy** and **No Answer** are independent choices.
4. Set an optional primary ring limit and enable failover when ready to test.
5. Save and Apply Config.
6. Select **Failover Control → [Control Name]** in the intended Inbound Route or another compatible FreePBX destination selector, then Apply Config again.

Disabling a control's failover keeps primary routing active. The enable/disable state is stored in the database and requires Apply Config to take effect.

Only the selected destination type's fields are shown. Switching types preserves entered values until saving; inactive fields are excluded from browser validation and submission. Server-side validation remains authoritative.

### Supported destinations

| Role | Supported destination | Behaviour |
| --- | --- | --- |
| Primary | Existing enabled PJSIP trunk | Supervised through Core's trunk dialling path |
| Failover | Another enabled non-ENUM Core trunk | Native `ext-trunk` handoff using the inbound DID |
| Failover | Existing Miscellaneous Destination | Native `ext-miscdests` handoff to its saved dial target |
| Failover | UK geographic/mobile number | Dialled through an existing eligible outbound route |

The UK-number option accepts geographic `01`/`02` and mobile `071`-`075`, `077`-`079` numbers. The selected route must match the number, have enabled trunks and preserve the destination through route/trunk rewrites. Password-protected, time-conditioned, emergency and chained-destination routes are not supported.

Native targets use their existing FreePBX configuration. The primary trunk cannot also be its own failover trunk. UK-number and numeric Miscellaneous Destination targets are checked against local inbound DIDs to prevent obvious routing loops.

## Call Handling

The primary call passes digit-validated `FROM_DID`, including an optional leading plus, to Core without constructing a raw SIP URI. Core applies its trunk dial rules and prefixes, caller-ID policy, dial options, channel limits and SIP-header hooks.

A nonzero ring limit sets Core's `TRUNK_RING_TIMER` for the whole primary attempt, including ringing. `0` leaves the existing Core timer unchanged; it does not promise an unlimited call attempt.

- An answered primary call does not trigger failover, including if dialplan execution resumes after the answered call ends.
- Caller cancellation does not qualify.
- Unavailable handling uses SIP 408/503/504 and selected Asterisk status/cause pairs. SIP 408 and cause 18 qualify as unavailability only without observed ringing.
- Busy requires the Busy condition. Ordinary no answer requires the No Answer condition.
- SIP 408 after ringing can qualify under No Answer only when that condition is selected and `DIALSTATUS=NOANSWER`.
- Explicit rejection is not an independently supported failover condition. Unrecognised outcomes do not authorise a fallback call.

The UK number uses `Local/<number>@outrt-<route-id>/n`. Native trunk and Miscellaneous Destination handoffs are not supervised again by this module. An inherited channel guard prevents local recursive re-entry; it cannot prevent distributed loops through remote systems.

## Configuration Verification

The page and `fwconsole failovercontrol:status` compare saved settings and current dependency metadata with the fingerprint in the active status extension. They also inspect the active control's ordered instructions, labels, selected destination and relevant Core dialplan dependencies.

**Control Enabled** and **Control Disabled** refer to the control itself. A control remains valid when no Inbound Route uses it. FreePBX destination references are reported where available, but the verifier does not prove that an Inbound Route points to the control or that a live call/carrier path works.

### Troubleshooting

- **Reload Required:** saved settings, dependency metadata or active routing differ. Inspect the detail, repair the dependency if necessary and Apply Config.
- **Configuration Error:** a required destination, Core dial path or control instruction is missing or inconsistent. Check the selected resources and active dialplan.
- **Unknown:** Asterisk/AMI could not confirm the queried dialplan. Restore AMI access before relying on the status.
- **No fallback call:** confirm failover is enabled, Apply Config has completed, and the actual `DIALSTATUS`, technology cause and ringing state meet a selected condition.

Use [Technical Notes](docs/TECHNICAL.md) for the failure allowlist and [Live Testing](docs/LIVE_TESTING.md) for read-only inspection commands, controlled calls and rollback.

## Current Limitations

Only PJSIP trunks are supervised as primary destinations. Extensions, Queues, Ring Groups and arbitrary application destinations are not supported as primaries. Other applications can select a control only where they supply a valid `FROM_DID`.

The verifier checks control configuration and selected dependency evidence, not the complete effective configuration of every external application or carrier. Live SIP response mapping, ringing detection, caller-ID behaviour, native transfers and PSTN acceptance remain to be tested on the target installation.

The module has no DOMAINTAINS, WHMCS or carrier-specific dependencies, and does not provide additional carrier resilience. It does not modify existing trunks, routes or FreePBX Firewall rules.

## Validation

From the module directory, run:

```sh
php tests/policy_contract.php
php tests/repository_contract.php
php tests/schema_contract.php
php tests/dialplan_contract.php
php tests/status_contract.php
php tests/lifecycle_contract.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php -r 'exit(simplexml_load_file("module.xml") === false ? 1 : 0);'
```

The contracts require PHP CLI with PDO SQLite, `mbstring` and SimpleXML for the metadata check. They cover policy, persistence, migration, generation, status and lifecycle behaviour using FreePBX/Asterisk doubles. They do not prove Asterisk parser acceptance or successful live calls. Follow [Live Testing](docs/LIVE_TESTING.md) separately and record the results. Development changes are recorded in [CHANGELOG.md](CHANGELOG.md).

The destination-field DOM regression uses Node.js with `jsdom` installed in the development environment (not a module runtime dependency):

```sh
node tests/destination_fields_contract.js
```

It renders the actual PHP view and checks field visibility, switching, value retention, validation, submitted fields and repository round-trips. Check the form in the installed FreePBX interface as well.

## Uninstalling

Change any FreePBX destinations which reference a control before deleting it or disabling/uninstalling the module, then Apply Config. The module refuses those actions while its controls remain referenced. Switching off a control's failover is different: that retains its primary routing and destination.

Uninstall removes the module-owned controls table. It does not remove existing trunks, outbound routes or Miscellaneous Destinations.

## Licence

GPLv3 or later. See [LICENSE](LICENSE).

## AI-assisted contributions and disclosure

AI assistance used for code, review, testing or documentation must be disclosed in each affected commit:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: Codex:gpt-5.6-sol`

The human contributor remains solely responsible. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)
