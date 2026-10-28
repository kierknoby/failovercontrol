# Changelog

## 0.2.1 - Destination field visibility

- Show only the failover fields relevant to the selected destination type when adding or editing a control.
- Update visibility immediately on selection changes, retaining entered values until the form is saved.
- Disable inactive destination fields so they do not participate in browser validation or form submission; retain existing server-side validation and persistence.

## 0.2.0 - Supervised primary and native failover destinations

- Remove primary trunk membership in outbound routes as a configuration requirement; continue to use Core's supervised trunk macro.
- Add existing Core trunk and Miscellaneous Destination failover targets alongside the existing UK number/outbound-route option.
- Make unavailable, busy and no-answer conditions independently selectable, including SIP 408 after ringing.
- Migrate existing controls to the number destination type without changing their saved numbers or routes.
- Clarify that status verifies the control, not the Inbound Route that may use it.
- Align active verification with the separate SIP timeout-after-ringing branch; check ordered instructions, labels and disabled primary-only paths using generation-derived fixtures.
- Store hangup-cause keys before selecting a channel with `CUT()`, which requires a variable name.
- Clear upstream `DIALSTATUS` before the primary attempt so a Core return without `Dial()` cannot trigger fallback using a previous leg's result.
- Keep PHP and generated SIP 408-after-ringing decisions equivalent even when a numeric transport cause is also present.
- Preserve Core primary result handling when no selected condition qualifies, and detect stale active Miscellaneous Destination dial targets.
- Align the README with the related FreePBX modules and correct individual-module installation instructions.

## 0.1.0 - Initial development milestone

- Add a FreePBX 17 module and native inbound-route destination provider.
- Add named persistent controls for PJSIP primary trunks and UK PSTN failover.
- Use selected FreePBX outbound routes; reject unsupported or unsafe route configurations.
- Add conservative SIP response handling with busy/no-answer opt-ins.
- Add a CSRF-protected administrative interface, destination dependency checks, and read-only effective-configuration status.
- Add standalone policy, repository, and dialplan-generation contracts.

See [README.md](README.md) for integration requirements and current limitations, and [Live Testing](docs/LIVE_TESTING.md) for checks not yet performed.