<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FailoverPolicy.php';

use FailoverControl\FailoverPolicy;

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
};

foreach ([
    '07876838629' => '07876838629',
    '+447876838629' => '07876838629',
    '447876838629' => '07876838629',
    '00447876838629' => '07876838629',
    '00442079460123' => '02079460123',
    '+44 (20) 7946-0123' => '02079460123',
] as $input => $expected) {
    $assertSame($expected, FailoverPolicy::normaliseUkTelephoneNumber((string) $input), 'UK number normalisation failed: ' . $input);
}

foreach (['999', '07012345678', '07612345678', '09012345678', '07876x38629', '123456789', '0207946012'] as $input) {
    $assertSame(null, FailoverPolicy::normaliseUkTelephoneNumber($input), 'Forbidden or malformed number accepted: ' . $input);
}

$enabled = ['enabled' => true, 'on_unavailable' => true, 'on_busy' => false, 'on_no_answer' => false];
$assertSame(true, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 408 Request Timeout', $enabled), 'SIP request timeout was not accepted');
$assertSame(true, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 503 Service Unavailable', $enabled), 'SIP service-unavailable response was not accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CONGESTION', null, $enabled, 34), 'Ambiguous cause 34 was accepted without a PJSIP response');
$assertSame(true, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 504 Gateway Timeout', $enabled), 'SIP gateway timeout was not accepted');
$assertSame(true, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 408 Request Timeout', $enabled), 'SIP timeout reported as NOANSWER was not accepted');
$assertSame(false, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 408 Request Timeout', $enabled, null, true), 'SIP 408 after ringing was accepted as unavailability');
$assertSame(true, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 503 Service Unavailable', $enabled, null, true), 'Explicit service failure after ringing was rejected');
$assertSame(true, FailoverPolicy::shouldFailover('NOANSWER', null, $enabled, 18), 'No-response timer cause was not accepted');
$assertSame(false, FailoverPolicy::shouldFailover('NOANSWER', null, $enabled, 18, true), 'Cause 18 after ringing was accepted as unavailability');
$assertSame(true, FailoverPolicy::shouldFailover('CHANUNAVAIL', null, $enabled, 20), 'Unregistered PJSIP endpoint cause was not accepted');
$assertSame(true, FailoverPolicy::shouldFailover('CONGESTION', null, $enabled, 38), 'Network-out-of-order cause was not accepted');
$assertSame(false, FailoverPolicy::shouldFailover('NOANSWER', null, $enabled, 19), 'Ordinary ring timeout cause was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', null, $enabled, 21), 'Explicit rejection without technology text was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 401 Unauthorized', $enabled), 'Authentication failure was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 403 Forbidden', $enabled), 'Forbidden destination was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 603 Decline', $enabled), 'Explicit rejection was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('BUSY', 'SIP 486 Busy Here', $enabled), 'Busy call was accepted by default');
$assertSame(false, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 480 Temporarily Unavailable', $enabled), 'No-answer call was accepted by default');
$assertSame(false, FailoverPolicy::shouldFailover('ANSWER', 'SIP 503 Service Unavailable', $enabled), 'Answered call was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CANCEL', 'SIP 408 Request Timeout', $enabled), 'Cancelled call was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', null, $enabled), 'Missing technology cause was accepted');
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'PJSIP transport unavailable', $enabled), 'Unknown cause was accepted');

$disabled = $enabled;
$disabled['enabled'] = false;
$assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 408 Request Timeout', $disabled), 'Disabled control initiated failover');

$optional = $enabled;
$optional['on_busy'] = true;
$optional['on_no_answer'] = true;
$assertSame(true, FailoverPolicy::shouldFailover('BUSY', 'SIP 486 Busy Here', $optional), 'Explicitly enabled busy failover was ignored');
$assertSame(true, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 480 Temporarily Unavailable', $optional), 'Explicitly enabled no-answer failover was ignored');

foreach (range(0, 7) as $mask) {
    $settings = [
        'enabled' => true,
        'on_unavailable' => (bool) ($mask & 1),
        'on_busy' => (bool) ($mask & 2),
        'on_no_answer' => (bool) ($mask & 4),
    ];
    $unavailable = (bool) ($mask & 1);
    $busy = (bool) ($mask & 2);
    $noAnswer = (bool) ($mask & 4);
    $assertSame($unavailable, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 503 Service Unavailable', $settings), 'Unavailable setting combination failed: ' . $mask);
    $assertSame($busy, FailoverPolicy::shouldFailover('BUSY', 'SIP 486 Busy Here', $settings), 'Busy setting combination failed: ' . $mask);
    $assertSame($noAnswer, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 480 Temporarily Unavailable', $settings, 19, true), 'Ordinary no-answer setting combination failed: ' . $mask);
    $assertSame($noAnswer, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 408 Request Timeout', $settings, 18, true), 'SIP 408 after ringing did not respect the independent no-answer setting: ' . $mask);
    $assertSame($unavailable || $noAnswer, FailoverPolicy::shouldFailover('NOANSWER', 'SIP 408 Request Timeout', $settings, 18, false), 'SIP 408 before ringing did not match a selected condition: ' . $mask);
    $assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 408 Request Timeout', $settings, null, true), 'SIP 408 after ringing with CHANUNAVAIL bypassed the no-answer DIALSTATUS guard: ' . $mask);
    $assertSame(false, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 408 Request Timeout', $settings, null, true), 'SIP 408 after ringing with CONGESTION bypassed the no-answer DIALSTATUS guard: ' . $mask);
    $assertSame(false, FailoverPolicy::shouldFailover('CHANUNAVAIL', 'SIP 408 Request Timeout', $settings, 38, true), 'Numeric cause overrode an observed SIP 408 after ringing: ' . $mask);
    $assertSame(false, FailoverPolicy::shouldFailover('CONGESTION', 'SIP 408 Request Timeout', $settings, 102, true), 'Numeric timer cause overrode an observed SIP 408 after ringing: ' . $mask);
}
$disabledConditions = ['enabled' => false, 'on_unavailable' => true, 'on_busy' => true, 'on_no_answer' => true];
$assertSame(false, FailoverPolicy::shouldFailover('BUSY', 'SIP 486 Busy Here', $disabledConditions), 'Disabled control ignored the all-conditions guard');

foreach ([
    ['02079460123', '_020NXXXXXXX', true],
    ['02079460123', '_020XXXXXXXX', true],
    ['07876838629', '_07[1-57-9]XXXXXXXX', true],
    ['07876838629', '_07NXXXXXXXX', true],
    ['07876838629', '_020NXXXXXXX', false],
    ['07876838629', '_07! ', false],
    ['07876838629', '_07!', true],
    ['07876838629', '_07[abc]XXXXXXX', false],
] as [$number, $pattern, $expected]) {
    $assertSame($expected, FailoverPolicy::matchesAsteriskPattern($number, $pattern), 'Outbound route pattern matching failed: ' . $pattern);
}

$assertSame(false, FailoverPolicy::isTrunkDisabled('off'), 'FreePBX enabled trunk value was misclassified');
$assertSame(true, FailoverPolicy::isTrunkDisabled('on'), 'FreePBX disabled trunk value was misclassified');
$assertSame(true, FailoverPolicy::isTrunkDisabled('1'), 'Numeric disabled trunk value was misclassified');
$assertSame(false, FailoverPolicy::isTrunkDisabled(''), 'Unset trunk disabled value was misclassified');

$routeCore = new class {
    public array $routePattern = ['match_pattern_prefix' => '', 'match_pattern_pass' => '_07[1-57-9]XXXXXXXX', 'prepend_digits' => '', 'match_cid' => ''];
    public array $trunkRule = [];
    public string $trunkPrefix = '';

    public function getRoutePatternsByID(int $id): array { return [$this->routePattern]; }
    public function getRouteTrunksByID(int $id): array { return [5]; }
    public function getTrunkByID(int $id): array { return ['trunkid' => $id, 'disabled' => 'off', 'dialoutprefix' => $this->trunkPrefix]; }
    public function getTrunkDialRulesByID(int $id): array { return $this->trunkRule ? [$this->trunkRule] : []; }
};
$assertSame(true, FailoverPolicy::routePreservesUkDestination(7, '07876838629', $routeCore), 'Unmodified outbound routing was rejected');
$routeCore->routePattern['match_pattern_prefix'] = '0';
$routeCore->routePattern['match_pattern_pass'] = '7[1-57-9]XXXXXXXX';
$routeCore->routePattern['prepend_digits'] = '44';
$assertSame(true, FailoverPolicy::routePreservesUkDestination(7, '07876838629', $routeCore), 'UK international-format prepend was rejected');
$routeCore->routePattern['prepend_digits'] = '9';
$assertSame(false, FailoverPolicy::routePreservesUkDestination(7, '07876838629', $routeCore), 'Non-UK route prepend was accepted');
$routeCore->routePattern['match_pattern_prefix'] = '';
$routeCore->routePattern['match_pattern_pass'] = '_07[1-57-9]XXXXXXXX';
$routeCore->routePattern['prepend_digits'] = '';
$routeCore->trunkPrefix = '9';
$assertSame(false, FailoverPolicy::routePreservesUkDestination(7, '07876838629', $routeCore), 'Non-UK trunk dial prefix was accepted');
$routeCore->trunkPrefix = '';
$routeCore->trunkRule = ['match_pattern_prefix' => '0', 'match_pattern_pass' => '7[1-57-9]XXXXXXXX', 'prepend_digits' => '44', 'match_cid' => ''];
$assertSame(true, FailoverPolicy::routePreservesUkDestination(7, '07876838629', $routeCore), 'UK international-format trunk rewrite was rejected');

echo "Failover policy contracts passed\n";