# Controlled FreePBX 17 Test Procedure

This runbook is for an isolated test system or an explicitly approved maintenance window. Do not run failure simulations or PSTN calls against a production number without operator approval. The module has not yet passed this procedure.

## Prerequisites And Baseline

1. Use FreePBX 17 with the target Asterisk 20 build and an enabled PJSIP trunk to a test PBX.
2. For UK-number failover tests, provide a separate authorised outbound route and a test UK mobile/geographic destination. Confirm the route's caller-ID and dial-pattern policy with the carrier. For native targets, provide an approved secondary trunk and Miscellaneous Destination.
3. Confirm Core has generated `macro-dialout-trunk` from a standard outbound route. The primary trunk used in this test should not be attached to a patterned outbound route; this verifies that primary selection has no route-membership dependency.
4. For UK-number failover tests, confirm the route has no password, time condition, emergency flag or secondary route destination, and that route/trunk dial rules preserve the chosen number.
5. Record the current test Inbound Route destination, primary trunk ID, outbound route ID, route patterns, trunk dial rules and caller-ID policy.
6. Back up the FreePBX database and `/etc/asterisk` using the site's normal backup procedure. Do not rely on this module as a backup tool.

Record versions and existing module state:

```sh
fwconsole --version
asterisk -rx 'core show version'
fwconsole ma list | grep -i failovercontrol
```

If `fwconsole ma list` or the command syntax differs on the target, use the Module Admin GUI and verify command help before proceeding.

## Installation

1. Place the reviewed 0.2.1 module files in `/var/www/html/admin/modules/failovercontrol/` (or the site's configured equivalent). From a neutral directory, run `fwconsole ma install failovercontrol`, `fwconsole chown` and `fwconsole reload`. Do not run `fwconsole ma installlocal`: it operates on all local modules, not this individual module.
2. Enable Failover Control in Module Admin. Do not disable or remove any trunk or existing route.
3. Apply Config once, in the controlled test environment.
4. Confirm the menu at **Applications → Failover Control**.
5. Confirm the provider appears in the Inbound Route **Set Destination** selector. Create a test control, select the existing primary PJSIP trunk, configure an approved UK number and eligible route or select an existing trunk/Miscellaneous Destination, and leave the control disabled initially.
6. Apply Config and inspect the generated contexts below before directing test calls through them.
7. Check `fwconsole ma list` and `module.xml` both report 0.2.1. Test a new installation and an upgrade from a backed-up legacy controls table separately; confirm names, numbers, routes and condition settings survive the MariaDB migration and a repeated installation.
8. On Add and Edit, switch between all three destination types. Confirm only the relevant fields are visible, unsaved values survive switching, and saving/reopening retains the selected destination. Inactive fields must not block submission.

## Read-Only Configuration Inspection

Use Asterisk CLI or the AMI-backed module status page. The following commands inspect state; they do not reload configuration or place calls.

```sh
asterisk -rx 'dialplan show app-failovercontrol'
asterisk -rx 'dialplan show <CONTROL_UUID>@app-failovercontrol'
asterisk -rx 'dialplan show fc-status-<CONTROL_UUID>@app-failovercontrol'
asterisk -rx 'dialplan show <PRIMARY_TRUNK_ID>@ext-trunk'
asterisk -rx 'dialplan show macro-dialout-trunk'
asterisk -rx 'dialplan show outrt-<OUTBOUND_ROUTE_ID>'
asterisk -rx 'pjsip show endpoint <PRIMARY_PJSIP_ENDPOINT>'
```

Replace angle-bracketed values with the displayed UUID/IDs; do not type the brackets. The control extension should call Core's `macro-dialout-trunk` with the primary trunk ID and validated `FC_DID`, and should contain the selected failure branches. The primary trunk must not need to appear in an outbound route. The status extension contains the generated fingerprint. Only for a UK-number failover, the selected route context should contain a matching pattern and Core trunk dial invocation. For native failover targets, inspect the selected `ext-trunk` or `ext-miscdests` extension. Confirm that caller-ID handling and any prefixes match the approved test design.

For Unavailable, confirm `HangupCauseClear()` precedes the primary attempt, `FC_PEERS` stores `HANGUPCAUSE_KEYS()`, and `CUT(FC_PEERS,\,,1)` supplies the channel to `HANGUPCAUSE(channel,tech)`. Confirm both labelled timeout branches appear: no observed ringing goes to `failover`; observed ringing goes to `test_timeout_after_ringing`, which checks `DIALSTATUS=NOANSWER` and the selected No Answer setting. With No Answer off, that branch must go to `primary_result` for either outcome.

The module CLI command is intended to be `fwconsole failovercontrol:status`; its discovery must be verified on the target before using it as an operational gate. It should report Unknown/error rather than success when AMI cannot inspect active dialplan.

## Test Inbound Route Setup

1. Clone or create a test-only Inbound Route that cannot receive public customer traffic.
2. Record its original destination.
3. Change only this test route's destination to **Failover Control → [test control]**.
4. Apply Config and verify both the route's generated reference and the module status.
5. Keep an operator at both PBX consoles and use test caller/test DID only.

## Functional Calls

Enable verbose call diagnostics in a test window:

```sh
asterisk -rvvv
```

At the Asterisk CLI:

```text
core set verbose 5
pjsip set logger on
```

After each test, turn SIP logging back off with `pjsip set logger off` and restore the prior verbosity setting. SIP logs may contain telephone numbers and signalling data; store them according to site policy and redact before sharing.

### Primary answers

1. Ensure the customer/test PBX is reachable and rings a controlled test extension.
2. Place one inbound test call through the test DID.
3. Confirm the called DID arriving at the customer PBX follows the configured Core trunk rules and prefixes, including the expected optional leading plus behaviour. Compare caller ID, privacy and SIP headers with a baseline call through the same trunk.
4. Answer, speak briefly, then hang up normally.
5. Confirm no second PSTN call occurred and no failover-attempt marker was logged.
6. Repeat with the called party ending the answered call first and with the caller ending it first, including any existing Core `g` dial option. Neither case may initiate fallback.

### Primary unavailable / no response

1. On the isolated test PBX, arrange an actual PJSIP transport timeout or make the target endpoint unavailable without changing the FreePBX SBC's saved trunk configuration. Do not simulate this on a production trunk.
2. Place one inbound test call.
3. Record `DIALSTATUS`, `${HANGUPCAUSE}`, the PJSIP technology response from `HANGUPCAUSE_KEYS()`/`HANGUPCAUSE(channel,tech)` where present, and the Asterisk call ID.
4. Confirm failover occurs only for a classified cause from the implemented allowlist and enters the selected fallback once. Confirm the stored cause-key channel belongs to this primary attempt, not an earlier leg.
5. Restore the test PBX immediately and confirm the next call returns to the primary.

Cause numbers vary by signalling path. A test passing with SIP 408 does not prove that a transport timeout without a response is exposed as cause 18 on every target build. If the observed result is absent or ambiguous, do not broaden the policy in production; report it and keep failover disabled until reviewed.

Test SIP 503/504, an unregistered endpoint and a network/transport failure separately. Record technology text, numeric cause and ringing state for each. Check the nonzero primary timer and `0` (existing Core timer unchanged); do not infer connection-establishment timing from a ring limit.

In the lab, test a primary channel-limit refusal or existing predial-hook bypass before `Dial()`. Confirm the cleared `DIALSTATUS` does not reuse an earlier leg's busy/no-answer result and initiate an unintended fallback.

### UK-number / PSTN fallback

1. Select the approved UK number and eligible route, with only Unavailable selected initially.
2. Arrange one qualifying primary failure and confirm one `Local/<number>@outrt-<id>/n` fallback attempt through that route.
3. Confirm the carrier receives the approved destination and caller ID after its existing route/trunk rules. Answer and verify two-way audio.
4. Repeat with the fallback busy or unanswered. Confirm no return to the primary and no second module fallback attempt.
5. Restore the primary and confirm the next call uses it without a fallback attempt. Record carrier acceptance separately from the module's configuration status.

### Native failover destinations

1. With a controlled test primary failure, select an enabled secondary Core trunk and confirm one handoff to `ext-trunk,<id>,1`, carrying the inbound DID through Core's native destination.
2. Repeat with a test Miscellaneous Destination and confirm one handoff to `ext-miscdests,<id>,1` and its saved native dial target.
3. Confirm the primary trunk cannot also be selected as its own failover trunk, that local inbound DID loops are rejected, and that a deleted target is not emitted as a fallback.
4. Confirm the destination dependency listing reports the selected native target. If a target is removed despite that reference, the control must report the missing dependency and must not issue a fallback transfer.

### Ordinary unanswered, busy, rejection and cancellation

1. Configure the test PBX to ring without answering until the primary timer ends. With **Failover on No Answer** off, confirm no PSTN call occurs, including if the test endpoint returns SIP 408 after it already sent ringing.
2. Repeat with test PBX returning SIP 486. With busy failover off, confirm no PSTN call.
3. Return SIP 401/403 or 603 in a controlled test. Record the resulting `DIALSTATUS` and numeric cause. With only Unavailable selected, confirm no failover on explicit rejection. A normal authentication challenge followed by successful authentication is not a failed call.
4. Cancel the inbound call while the primary is ringing. Confirm no failover.
5. If testing opt-in busy/no-answer, use a test destination and explicitly approved call charges; verify only the corresponding outcome triggers.
6. Test Unavailable alone, Unavailable plus No Answer, Busy alone, No Answer alone and all conditions together. SIP 408 after ringing must qualify only under a selected No Answer condition with `DIALSTATUS=NOANSWER`, not `CHANUNAVAIL` or `CONGESTION`.

### Disabled behaviour

1. Disable the control using the UI, Apply Config, and verify status **Control Disabled** with active control configuration confirmed.
2. Make one call while primary is available; confirm primary still works.
3. Make one controlled unavailable call; confirm there is no fallback attempt.
4. Re-enable, Apply Config, and verify status before continuing.

## Configuration Read-Back And Persistence

1. Apply Config and repeat status inspection after a controlled Asterisk reload.
2. If a server restart is part of the approved lab plan, verify status and routing afterward.
3. A matching fingerprint proves configuration presence, not a successful live PSTN call.
4. Save a changed condition without applying it. Confirm status reports Reload Required, then Apply Config and confirm it verifies again. Repeat for the selected target and primary trunk settings.
5. Confirm an unused control can verify while the status detail says inbound-route usage is not verified. Separately inspect the test Inbound Route destination.
6. With a lab-only copy of generated configuration, test a missing timeout label/branch, changed fallback and unexpected transfer in a disabled control. Reload only in the isolated lab and confirm none verifies. Regenerate through Apply Config afterwards; do not keep hand-edited generated files.
7. Confirm AMI responses on this build match the verifier's numbered, labelled instruction parsing. If they differ, retain redacted output and report Unknown/error rather than accepting a success claim.

## Diagnostics

Useful read-only checks:

```sh
asterisk -rx 'core show channels verbose'
asterisk -rx 'pjsip show endpoint <PRIMARY_PJSIP_ENDPOINT>'
asterisk -rx 'dialplan show <CONTROL_UUID>@app-failovercontrol'
asterisk -rx 'dialplan show outrt-<OUTBOUND_ROUTE_ID>'
fwconsole failovercontrol:status
```

In the Asterisk CLI, `pjsip set logger on` shows SIP exchanges, and `core set verbose 5` provides dialplan/call detail. Correlate with the Asterisk unique ID and the module's `FAILOVERCONTROL` NoOp markers. Avoid sharing unredacted caller numbers or SIP headers.

## Rollback

1. Open the test Inbound Route and restore its original destination recorded in the baseline. Do not delete, recreate or edit the existing primary trunk.
2. Apply Config and verify the test route no longer points to Failover Control.
3. Place a primary-only test call and confirm it follows the original destination.
4. Change all remaining references before deleting the test control or disabling the module; those actions are refused while a control is in use. Switching off a control's failover is permitted while it is referenced and keeps primary routing active.
5. Uninstall only after all test routes no longer reference Failover Control. Retain the backup until the route and call tests are accepted.

Do not use `fwconsole restart` for routine configuration changes. Use the normal Apply Config/`fwconsole reload` process and the site's approved rollback plan.
