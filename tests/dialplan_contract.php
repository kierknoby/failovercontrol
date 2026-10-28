<?php
declare(strict_types=1);

define('FREEPBX_IS_AUTH', true);

if (!function_exists('_')) {
    function _(string $message): string { return $message; }
}

final class FakeCore
{
    public function getTrunkByID(int $id): array
    {
        return ['trunkid' => $id, 'tech' => 'pjsip', 'channelid' => $id === 3 ? 'primary-pbx' : 'failover-pbx', 'name' => $id === 3 ? 'Primary PBX' : 'Failover PBX', 'disabled' => 'off'];
    }

    public function getTrunkDialRulesByID(int $id): array
    {
        return [];
    }

    public function getAllRoutes(): array
    {
        return [['route_id' => 7, 'name' => 'UK failover']];
    }

    public function getRoutePatternsByID(int $id): array
    {
        return [['match_pattern_prefix' => '', 'match_pattern_pass' => '_0[127]XXXXXXXXX', 'match_cid' => '']];
    }

    public function getRouteTrunksByID(int $id): array
    {
        return [5];
    }
}

final class FakeMiscDestinations
{
    public function get(int $id): array|false
    {
        if ($id === 9) {
            return [['id' => 9, 'description' => 'Test Misc', 'destdial' => '601']];
        }
        return false;
    }
}

final class FakeDestinationRegistry
{
    public function getModuleDestinations(string $module): array
    {
        return $module === 'miscdests'
            ? [
                ['destination' => 'ext-miscdests,9,1', 'description' => 'Test Misc'],
            ]
            : [];
    }
}

final class FreePBX
{
    public static PDO $database;
    public static FakeCore $core;
    public static FakeMiscDestinations $miscDestinations;
    public static FakeDestinationRegistry $destinationRegistry;

    public static function Database(): PDO
    {
        return self::$database;
    }

    public static function Core(): FakeCore
    {
        return self::$core;
    }

    public static function Miscdests(): FakeMiscDestinations
    {
        return self::$miscDestinations;
    }

    public static function Destinations(): FakeDestinationRegistry
    {
        return self::$destinationRegistry;
    }
}

require_once __DIR__ . '/dialplan_fixture.php';

$generatedShouldFailover = static function (FakeExtensions $ext, string $id, string $status, ?string $techCause, ?int $cause, bool $rang): bool {
    $commands = $ext->entries[$id];
    $labels = array_flip(array_filter($ext->labels[$id]));
    $variables = ['DIALSTATUS' => $status, 'RINGTIME' => $rang ? '1' : ''];
    $index = 0;
    foreach ($commands as $position => $command) {
        if ($command instanceof ext_macro && $command->macro === 'dialout-trunk') {
            $index = $position + 1;
            break;
        }
    }
    for ($steps = 0; $steps < count($commands); $steps++) {
        $label = $ext->labels[$id][$index] ?? '';
        if ($label === 'failover') {
            return true;
        }
        if (in_array($label, ['done', 'primary_result'], true)) {
            return false;
        }
        $command = $commands[$index] ?? null;
        if ($command instanceof ext_gotoif) {
            $expression = $command->condition;
            if (preg_match('/^\$\{REGEX\("([^"]+)" \$\{FC_TECH_CAUSE\}\)\}$/', $expression, $match) === 1) {
                $result = preg_match('/' . $match[1] . '/', $techCause ?? '') === 1;
            } else {
                $expression = preg_replace_callback('/\$\{([A-Z_]+)\}/', static function (array $match) use ($variables): string {
                    if (!array_key_exists($match[1], $variables)) {
                        throw new RuntimeException('Unhandled dialplan test variable: ' . $match[1]);
                    }
                    return $variables[$match[1]];
                }, $expression);
                $expression = preg_replace_callback('/"([^"]*)"\s*=\s*"([^"]*)"/', static fn (array $match): string => $match[1] === $match[2] ? '1' : '0', $expression);
                $expression = str_replace(['$[', ']', '(', ')', ' '], '', $expression);
                if (preg_match('/^[01&|]+$/', $expression) !== 1) {
                    throw new RuntimeException('Unhandled dialplan test expression: ' . $expression);
                }
                $result = true;
                foreach (explode('&', $expression) as $term) {
                    $result = $result && in_array('1', explode('|', $term), true);
                }
            }
            $target = $result ? $command->true : $command->false;
            $index = $target === false ? $index + 1 : $labels[$target];
        } elseif ($command instanceof ext_goto) {
            $index = $labels[$command->priority];
        } elseif ($command instanceof ext_setvar) {
            if ($command->var === 'FC_PEERS' && $command->value === '${HANGUPCAUSE_KEYS()}') {
                $variables['FC_PEERS'] = $techCause !== null ? 'PJSIP/test,PJSIP/other' : '';
            } elseif ($command->var === 'FC_PEER' && $command->value === '${CUT(FC_PEERS,\\,,1)}') {
                $variables['FC_PEER'] = explode(',', $variables['FC_PEERS'])[0];
            } elseif ($command->var === 'FC_TECH_CAUSE' && $command->value === '${HANGUPCAUSE(${FC_PEER},tech)}') {
                $variables['FC_TECH_CAUSE'] = $variables['FC_PEER'] === 'PJSIP/test' ? $techCause : '';
            } elseif ($command->var === 'FC_AST_CAUSE' && $command->value === '${HANGUPCAUSE}') {
                $variables['FC_AST_CAUSE'] = (string) $cause;
            } else {
                throw new RuntimeException('Unhandled dialplan test assignment: ' . $command->output());
            }
            $index++;
        } else {
            throw new RuntimeException('Unexpected command in failure-policy test: ' . get_debug_type($command));
        }
    }
    throw new RuntimeException('Generated failure policy did not terminate');
};

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
};

require_once __DIR__ . '/../src/FailoverPolicy.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../functions.inc.php';

FreePBX::$database = new PDO('sqlite::memory:');
FreePBX::$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FreePBX::$database->exec("CREATE TABLE failovercontrol_controls (
    id CHAR(36) PRIMARY KEY, name TEXT, description TEXT, primary_trunk_id INTEGER,
    outbound_route_id INTEGER, failover_type TEXT NOT NULL DEFAULT 'number',
    failover_destination_id TEXT NOT NULL DEFAULT '', failover_number TEXT, enabled INTEGER, on_unavailable INTEGER,
    on_busy INTEGER, on_no_answer INTEGER, ring_timeout INTEGER, updated_at TEXT
)");
FreePBX::$database->exec("INSERT INTO failovercontrol_controls VALUES
    ('11111111-1111-4111-8111-111111111111', 'Main Office', '', 3, 7, 'number', '', '07876838629', 1, 1, 0, 0, 45, '2026-10-27 00:00:00'),
    ('22222222-2222-4222-8222-222222222222', 'Sales', '', 3, NULL, 'number', '', '', 0, 1, 0, 0, 0, '2026-10-27 00:00:00'),
    ('33333333-3333-4333-8333-333333333333', 'No Answer Opt-in', '', 3, 7, 'number', '', '07876838629', 1, 1, 0, 1, 45, '2026-10-27 00:00:00'),
    ('44444444-4444-4444-8444-444444444444', 'Trunk Failover', '', 3, NULL, 'trunk', '5', '', 1, 0, 1, 0, 45, '2026-10-27 00:00:00'),
    ('55555555-5555-4555-8555-555555555555', 'Misc Failover', '', 3, NULL, 'misc_destination', '9', '', 1, 0, 0, 1, 45, '2026-10-27 00:00:00'),
    ('66666666-6666-4666-8666-666666666666', 'Primary Trunk Reused', '', 3, NULL, 'trunk', '3', '', 1, 0, 1, 0, 45, '2026-10-27 00:00:00'),
    ('77777777-7777-4777-8777-777777777777', 'Missing Misc Failover', '', 3, NULL, 'misc_destination', '10', '', 1, 0, 1, 0, 45, '2026-10-27 00:00:00')");
$combinationIds = [];
$combinationInsert = FreePBX::$database->prepare('INSERT INTO failovercontrol_controls
    (id, name, description, primary_trunk_id, outbound_route_id, failover_type, failover_destination_id,
     failover_number, enabled, on_unavailable, on_busy, on_no_answer, ring_timeout, updated_at)
    VALUES (:id, :name, \'\', 3, 7, \'number\', \'\', \'07876838629\', 1, :unavailable, :busy, :no_answer, 45, \'2026-10-27 00:00:00\')');
foreach (range(0, 7) as $mask) {
    $combinationIds[$mask] = sprintf('%08d-1111-4111-8111-%012d', 10000000 + $mask, 1000 + $mask);
    $combinationInsert->execute([
        ':id' => $combinationIds[$mask],
        ':name' => 'Condition Combination ' . $mask,
        ':unavailable' => (int) (bool) ($mask & 1),
        ':busy' => (int) (bool) ($mask & 2),
        ':no_answer' => (int) (bool) ($mask & 4),
    ]);
}
FreePBX::$core = new FakeCore();
FreePBX::$miscDestinations = new FakeMiscDestinations();
FreePBX::$destinationRegistry = new FakeDestinationRegistry();
$ext = new FakeExtensions();

failovercontrol_get_config('asterisk');

$registered = failovercontrol_destinations();
$assertSame(true, in_array('app-failovercontrol,11111111-1111-4111-8111-111111111111,1', array_column($registered, 'destination'), true), 'Control was not registered with FreePBX destinations');
$assertSame(['app-failovercontrol,11111111-1111-4111-8111-111111111111,1'], failovercontrol_getdest('11111111-1111-4111-8111-111111111111'), 'Control destination did not retain its stable identifier');
$assertSame('Failover Control: Main Office', failovercontrol_getdestinfo('app-failovercontrol,11111111-1111-4111-8111-111111111111,1')['description'], 'Control destination information was not resolved');
$assertSame(false, failovercontrol_getdestinfo('from-internal,999,1'), 'Unrelated destination was accepted as a control');

$primaryDial = false;
$fallbackDial = false;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $command) {
    if ($command instanceof ext_macro && $command->macro === 'dialout-trunk' && $command->args === '3,${FC_DID},,on') {
        $primaryDial = true;
    }
    if ($command instanceof ext_dial && $command->number === 'Local/07876838629@outrt-7/n') {
        $fallbackDial = true;
    }
}
$assertSame(true, $primaryDial, 'Configured PJSIP primary dial was not generated');
$assertSame(false, in_array(3, FreePBX::Core()->getRouteTrunksByID(7), true), 'Test primary trunk unexpectedly belongs to an outbound route');
$ringTimer = false;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $command) {
    if ($command instanceof ext_setvar && $command->var === 'TRUNK_RING_TIMER' && $command->value === '45') {
        $ringTimer = true;
    }
}
$assertSame(true, $ringTimer, 'Configured primary timeout was not passed through Core trunk dialling');
$causeClear = false;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $command) {
    if ($command instanceof extension && $command->data === 'HangupCauseClear()') {
        $causeClear = true;
    }
}
$assertSame(true, $causeClear, 'Prior hangup causes were not cleared before the supervised attempt');
$statusClearIndex = null;
$primaryCallIndex = null;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $index => $command) {
    if ($command instanceof ext_setvar && $command->var === 'DIALSTATUS' && $command->value === '') {
        $statusClearIndex = $index;
    }
    if ($command instanceof ext_macro && $command->macro === 'dialout-trunk') {
        $primaryCallIndex = $index;
    }
}
$assertSame(true, $statusClearIndex !== null && $primaryCallIndex !== null && $statusClearIndex < $primaryCallIndex, 'A stale upstream DIALSTATUS can survive a Core return without Dial, such as a channel-limit or hook bypass');
$causeKeysIndex = null;
$causePeerIndex = null;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $index => $command) {
    if ($command instanceof ext_setvar && $command->var === 'FC_PEERS' && $command->value === '${HANGUPCAUSE_KEYS()}') {
        $causeKeysIndex = $index;
    }
    if ($command instanceof ext_setvar && $command->var === 'FC_PEER' && $command->value === '${CUT(FC_PEERS,\\,,1)}') {
        $causePeerIndex = $index;
    }
}
$assertSame(true, $causeKeysIndex !== null && $causePeerIndex === $causeKeysIndex + 1, 'CUT must receive the name of the stored cause-key variable, not expanded channel names');
$assertSame(true, $fallbackDial, 'Enabled control did not generate the selected outbound-route fallback');
$trunkFallback = false;
$miscFallback = false;
foreach ($ext->entries['44444444-4444-4444-8444-444444444444'] ?? [] as $command) {
    if ($command instanceof ext_goto && $command->priority === '1' && $command->extension === '5' && $command->context === 'ext-trunk') {
        $trunkFallback = true;
    }
}
foreach ($ext->entries['55555555-5555-4555-8555-555555555555'] ?? [] as $command) {
    if ($command instanceof ext_goto && $command->priority === '1' && $command->extension === '9' && $command->context === 'ext-miscdests') {
        $miscFallback = true;
    }
}
$assertSame(true, $trunkFallback, 'Native failover trunk destination was not generated');
$assertSame(true, $miscFallback, 'Native Miscellaneous Destination was not generated');
foreach (['66666666-6666-4666-8666-666666666666', '77777777-7777-4777-8777-777777777777'] as $invalidControlId) {
    foreach ($ext->entries[$invalidControlId] ?? [] as $command) {
        $assertSame(false, $command instanceof ext_goto && in_array($command->context, ['ext-trunk', 'ext-miscdests'], true), 'Invalid or recursive native destination generated a transfer');
    }
}
$assertSame(true, count($ext->entries['fc-status-11111111-1111-4111-8111-111111111111'] ?? []) === 2, 'Read-only verifier extension was not generated');
$loopGuard = false;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $command) {
    if ($command instanceof ext_setvar && $command->var === '__FC_ACTIVE' && $command->value === '1') {
        $loopGuard = true;
    }
}
$assertSame(true, $loopGuard, 'Inherited recursive-call guard was not generated');
$didValidation = false;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $command) {
    if ($command instanceof ext_setvar && $command->var === 'FC_DID' && $command->value === '${FILTER(0-9+,${FROM_DID})}') {
        $didValidation = true;
    }
}
$assertSame(true, $didValidation, 'Inbound DID validation did not preserve an optional plus sign');
$answeredGuardIndex = null;
$firstFailureCheckIndex = null;
foreach ($ext->entries['11111111-1111-4111-8111-111111111111'] ?? [] as $index => $command) {
    if ($command instanceof ext_gotoif && str_contains($command->condition, '"ANSWER"') && $command->true === 'done') {
        $answeredGuardIndex = $index;
    }
    if ($command instanceof ext_gotoif && preg_match('/DIALSTATUS.*(BUSY|NOANSWER|CHANUNAVAIL|CONGESTION)/', $command->condition) === 1) {
        $firstFailureCheckIndex = $index;
        break;
    }
}
$assertSame(true, $answeredGuardIndex !== null && $firstFailureCheckIndex !== null && $answeredGuardIndex < $firstFailureCheckIndex, 'Failure branches can run before the successful-answer guard');

foreach (range(0, 7) as $mask) {
    $hasUnavailable = (bool) ($mask & 1);
    $hasBusy = (bool) ($mask & 2);
    $hasNoAnswer = (bool) ($mask & 4);
    $extension = $ext->entries[$combinationIds[$mask]] ?? [];
    $assertSame(false, $generatedShouldFailover($ext, $combinationIds[$mask], '', null, null, false), 'Core returning without Dial must not trigger fallback: ' . $mask);
    foreach (['ANSWER', 'CANCEL', 'BUSY', 'NOANSWER', 'CHANUNAVAIL', 'CONGESTION', 'DONTCALL', 'INVALIDARGS'] as $status) {
        foreach ([null, 'SIP 408 Request Timeout', 'SIP 503 Service Unavailable', 'SIP 504 Gateway Timeout', 'SIP 403 Forbidden', 'SIP 603 Decline', 'SIP 486 Busy Here'] as $techCause) {
            foreach ([null, 18, 19, 20, 21, 34, 38, 41, 42, 102] as $cause) {
                foreach ([false, true] as $rang) {
                    $settings = ['enabled' => true, 'on_unavailable' => $hasUnavailable, 'on_busy' => $hasBusy, 'on_no_answer' => $hasNoAnswer];
                    $assertSame(
                        \FailoverControl\FailoverPolicy::shouldFailover($status, $techCause, $settings, $cause, $rang),
                        $generatedShouldFailover($ext, $combinationIds[$mask], $status, $techCause, $cause, $rang),
                        'PHP/generated policy mismatch: ' . json_encode([$mask, $status, $techCause, $cause, $rang])
                    );
                }
            }
        }
    }
    $conditionEndIndex = count($extension);
    $classificationIndex = null;
    foreach ($extension as $index => $command) {
        if ($conditionEndIndex === count($extension) && $command instanceof ext_goto && in_array($command->priority, ['done', 'primary_result'], true)) {
            $conditionEndIndex = $index;
        }
        if ($classificationIndex === null && $command instanceof ext_setvar && $command->var === 'FC_PEER') {
            $classificationIndex = $index;
        }
    }
    $noAnswerDestination = null;
    $busyDestination = null;
    $timeoutResult = null;
    $timeoutAfterRingingDestination = null;
    $timeoutAfterRingingFalseDestination = null;
    $classifiedNoAnswer = false;
    foreach ($extension as $index => $command) {
        if (!$command instanceof ext_gotoif) {
            continue;
        }
        if (str_contains($command->condition, 'DIALSTATUS') && str_contains($command->condition, 'NOANSWER')) {
            if ($index < $conditionEndIndex) {
                $noAnswerDestination = $command->true;
            } elseif ($classificationIndex !== null && $index > $classificationIndex && $command->true === 'failover'
                && !str_contains($command->condition, 'FC_AST_CAUSE')) {
                $classifiedNoAnswer = true;
            }
        }
        if ($index < $conditionEndIndex && str_contains($command->condition, 'DIALSTATUS') && str_contains($command->condition, 'BUSY')) {
            $busyDestination = $command->true;
        }
        if (str_contains($command->condition, 'RINGTIME')) {
            $timeoutResult = $command->false;
        }
        if (str_contains($command->condition, 'DIALSTATUS') && str_contains($command->condition, 'NOANSWER') && $command->false === 'primary_result') {
            $timeoutAfterRingingDestination = $command->true;
            $timeoutAfterRingingFalseDestination = $command->false;
        }
    }
    $assertSame($hasUnavailable ? 'test_unavailable' : ($hasNoAnswer ? 'failover' : null), $noAnswerDestination, 'Generated NOANSWER route differs from selected condition combination: ' . $mask);
    $assertSame($hasBusy ? 'failover' : null, $busyDestination, 'Generated BUSY route differs from selected condition combination: ' . $mask);
    if ($hasUnavailable) {
        $assertSame('test_timeout_after_ringing', $timeoutResult, 'SIP 408 after ringing skipped its DIALSTATUS guard: ' . $mask);
        $assertSame($hasNoAnswer ? 'failover' : 'primary_result', $timeoutAfterRingingDestination, 'SIP 408 after ringing did not defer to the independent no-answer setting: ' . $mask);
        $assertSame('primary_result', $timeoutAfterRingingFalseDestination, 'SIP 408 after ringing incorrectly treated non-NOANSWER status as no-answer: ' . $mask);
        $assertSame($hasNoAnswer, $classifiedNoAnswer, 'Unavailable classification did not independently test selected no-answer policy: ' . $mask);
    } else {
        $assertSame(null, $timeoutResult, 'Unavailable-only timeout classifier was generated while unavailable was disabled: ' . $mask);
        $assertSame(null, $timeoutAfterRingingDestination, 'Timeout DIALSTATUS guard was generated without unavailable classification: ' . $mask);
        $assertSame(false, $classifiedNoAnswer, 'No-answer classifier was generated without unavailable classification: ' . $mask);
    }
}

$disabledHasFallback = false;
foreach ($ext->entries['22222222-2222-4222-8222-222222222222'] ?? [] as $command) {
    if ($command instanceof ext_dial && str_starts_with($command->number, 'Local/')) {
        $disabledHasFallback = true;
    }
}
$assertSame(false, $disabledHasFallback, 'Disabled control generated a PSTN fallback leg');
$assertSame(false, failovercontrol_check_destinations(['ext-trunk,5,1']) === null, 'Native trunk dependency was not reported');
$assertSame(false, failovercontrol_check_destinations(['ext-miscdests,9,1']) === null, 'Miscellaneous Destination dependency was not reported');
$assertSame(null, failovercontrol_check_destinations(['ext-trunk,999,1']), 'Unrelated native destination was reported as a dependency');

$unavailableCheckIndex = null;
$noAnswerFallbackIndex = null;
$causeSetIndex = null;
foreach ($ext->entries['33333333-3333-4333-8333-333333333333'] ?? [] as $index => $command) {
    if ($command instanceof ext_gotoif && str_contains($command->condition, 'DIALSTATUS') && str_contains($command->condition, 'NOANSWER') && $command->true === 'test_unavailable') {
        $unavailableCheckIndex = $index;
    }
    if ($command instanceof ext_gotoif && str_contains($command->condition, 'DIALSTATUS') && str_contains($command->condition, 'NOANSWER') && $command->true === 'failover') {
        $noAnswerFallbackIndex = $index;
    }
    if ($command instanceof ext_setvar && $command->var === 'FC_AST_CAUSE') {
        $causeSetIndex = $index;
    }
}
$assertSame(true, $unavailableCheckIndex !== null && $causeSetIndex !== null && $noAnswerFallbackIndex !== null, 'Combined unavailable/no-answer policy branches were not generated');
$assertSame(true, $unavailableCheckIndex < $causeSetIndex && $causeSetIndex < $noAnswerFallbackIndex, 'No-answer opt-in ran before availability cause classification');

echo "Failover dialplan-generation contracts passed\n";
