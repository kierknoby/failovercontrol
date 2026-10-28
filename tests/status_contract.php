<?php
declare(strict_types=1);

interface BMO {}

define('FREEPBX_IS_AUTH', true);
if (!function_exists('_')) {
    function _(string $message): string { return $message; }
}
require_once __DIR__ . '/dialplan_fixture.php';

final class FakeAsteriskManager
{
    public bool $connected = true;
    public array $responses = [];

    public function connected(): bool
    {
        return $this->connected;
    }

    public function Command(string $command): array
    {
        return $this->responses[$command] ?? ['Response' => 'Error', 'Output' => ''];
    }
}

final class FakeDestinationManagerForStatus
{
    public function getModuleDestinations(string $module): array
    {
        return $module === 'miscdests'
            ? [
                ['destination' => 'ext-miscdests,9,1', 'description' => 'Test Misc'],
                ['destination' => 'ext-miscdests,10,1', 'description' => 'Local DID loop'],
            ]
            : [];
    }

    public function getAllInUseDestinations(array $destinations): array
    {
        return [];
    }
}

final class FakeCoreForStatus
{
    public string $routeName = 'UK failover';
    public array $dids = [];
    public array $missingTrunks = [];

    public function getTrunkByID(int $id): array|false
    {
        if (in_array($id, $this->missingTrunks, true)) {
            return false;
        }
        return ['trunkid' => $id, 'tech' => 'pjsip', 'channelid' => $id === 3 ? 'primary-pbx' : 'failover-pbx', 'name' => $id === 3 ? 'Primary PBX' : 'Failover PBX', 'disabled' => 'off'];
    }

    public function getTrunkDialRulesByID(int $id): array
    {
        return [];
    }

    public function listTrunks(): array
    {
        return [$this->getTrunkByID(3)];
    }

    public function getAllRoutes(): array
    {
        return [['route_id' => 7, 'name' => $this->routeName, 'password' => '', 'time_group_id' => null, 'emergency_route' => '', 'dest' => '']];
    }

    public function getRoutePatternsByID(int $id): array
    {
        return [['match_pattern_prefix' => '', 'match_pattern_pass' => '_07[1-57-9]XXXXXXXX', 'match_cid' => '']];
    }

    public function getRouteTrunksByID(int $id): array
    {
        return [5];
    }

    public function getAllDIDs(): array
    {
        return $this->dids;
    }
}

final class FakeMiscDestinationsForStatus
{
    public bool $missing = false;

    public function get(int $id): array|false
    {
        if ($this->missing) {
            return false;
        }
        if ($id === 9) {
            return [['id' => 9, 'description' => 'Test Misc', 'destdial' => '601']];
        }
        if ($id === 10) {
            return [['id' => 10, 'description' => 'Local DID loop', 'destdial' => '07876838629']];
        }
        return false;
    }
}

final class FakeFreePBXForStatus
{
    public FakeAsteriskManager $astman;
    public FakeCoreForStatus $core;
    public FakeDestinationManagerForStatus $destinations;
    public FakeMiscDestinationsForStatus $miscDestinations;

    public function __construct()
    {
        $this->astman = new FakeAsteriskManager();
        $this->core = new FakeCoreForStatus();
        $this->destinations = new FakeDestinationManagerForStatus();
        $this->miscDestinations = new FakeMiscDestinationsForStatus();
    }

    public function Core(): FakeCoreForStatus
    {
        return $this->core;
    }

    public function Destinations(): FakeDestinationManagerForStatus
    {
        return $this->destinations;
    }

    public function Miscdests(): FakeMiscDestinationsForStatus
    {
        return $this->miscDestinations;
    }
}

final class FreePBX
{
    public static PDO $database;
    public static FakeFreePBXForStatus $service;

    public static function Database(): PDO
    {
        return self::$database;
    }

    public static function Core(): FakeCoreForStatus { return self::$service->Core(); }
    public static function Destinations(): FakeDestinationManagerForStatus { return self::$service->Destinations(); }
    public static function Miscdests(): FakeMiscDestinationsForStatus { return self::$service->Miscdests(); }
}

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': got ' . var_export($actual, true));
    }
};

require_once __DIR__ . '/../src/FailoverPolicy.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../Failovercontrol.class.php';
require_once __DIR__ . '/../functions.inc.php';

FreePBX::$database = new PDO('sqlite::memory:');
FreePBX::$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FreePBX::$database->exec("CREATE TABLE failovercontrol_controls (
    id CHAR(36) PRIMARY KEY, name TEXT, description TEXT, primary_trunk_id INTEGER,
    outbound_route_id INTEGER, failover_type TEXT NOT NULL DEFAULT 'number',
    failover_destination_id TEXT NOT NULL DEFAULT '', failover_number TEXT, enabled INTEGER, on_unavailable INTEGER,
    on_busy INTEGER, on_no_answer INTEGER, ring_timeout INTEGER, updated_at TEXT
)");

$repository = new FreePBX\modules\Failovercontrol\Repository(FreePBX::$database);
$id = $repository->save([
    'name' => 'Main Office',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '7',
    'failover_number' => '07876838629',
    'enabled' => '1',
    'on_unavailable' => '1',
    'ring_timeout' => '45',
]);
$control = $repository->find($id);
$service = new FakeFreePBXForStatus();
FreePBX::$service = $service;
$generate = static function (string $controlId) use ($service): void {
    global $ext;
    $ext = new FakeExtensions();
    failovercontrol_get_config('asterisk');
    foreach ([$controlId, 'fc-status-' . $controlId] as $extension) {
        $service->astman->responses['dialplan show ' . $extension . '@app-failovercontrol'] = [
            'Response' => 'Follows',
            'Output' => $ext->show($extension),
        ];
    }
};
$runtimeConfiguration = FailoverControl\FailoverPolicy::effectiveRouteMetadata($control, $service->Core());
$fingerprint = FailoverControl\FailoverPolicy::fingerprint($control, $runtimeConfiguration);
$service->astman->responses = [
    'dialplan show 3@ext-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'ext-trunk' created by 'pbx_config' ]\nSet(TDIAL_SUFFIX=@primary-pbx)"],
    'dialplan show macro-dialout-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'macro-dialout-trunk' created by 'pbx_config' ]\n"],
    'dialplan show outrt-7' => ['Response' => 'Follows', 'Output' => "[ Context 'outrt-7' created by 'pbx_config' ]\n'_07[1-57-9]XXXXXXXX' => 1. Gosub(macro-dialout-trunk,s,1(5,\${EXTEN},,))\n"],
];
$generate($id);

$module = new FreePBX\modules\Failovercontrol($service);
$method = new ReflectionMethod($module, 'configurationStatus');
$method->setAccessible(true);
$status = $method->invoke($module, $control);
$assertSame('Control Enabled', $status['label'], 'Correct loaded enabled configuration should verify');
$macroOutput = $service->astman->responses['dialplan show macro-dialout-trunk']['Output'];
$service->astman->responses['dialplan show macro-dialout-trunk']['Output'] = 'No such context';
$assertSame('Configuration Error', $method->invoke($module, $control)['label'], 'Missing shared Core macro incorrectly verified');
$service->astman->responses['dialplan show macro-dialout-trunk']['Output'] = $macroOutput;
$service->core->missingTrunks = [3];
$assertSame('Configuration Error', $method->invoke($module, $control)['label'], 'Deleted primary trunk incorrectly verified');
$service->core->missingTrunks = [];

$activeCallOutput = $service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'];
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = str_replace('GotoIf($["${DIALSTATUS}" = "CHANUNAVAIL"]?test_unavailable)', 'NoOp(missing classification)', $activeCallOutput);
$status = $method->invoke($module, $control);
$assertSame('Configuration Error', $status['label'], 'Missing active failure branches must not verify as enabled');
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = $activeCallOutput;

$mutations = [
    'legacy timeout branch' => ['?failover:test_timeout_after_ringing)', '?failover:primary_result)'],
    'missing timeout label' => ['[test_timeout_after_ringing]', '[wrong_timeout_label]'],
    'unguarded timeout failover' => ['?primary_result:primary_result)', '?failover:primary_result)'],
    'wrong SIP timeout target' => ['}?test_timeout)', '}?failover)'],
    'missing cause collection' => ['HangupCauseClear()', 'NoOp(causes not cleared)'],
    'stale upstream result' => ['Set(DIALSTATUS=)', 'NoOp(result not cleared)'],
    'missing ringing guard' => [' & "${RINGTIME}" = ""', ''],
    'answered call bypass' => ['"${DIALSTATUS}" = "ANSWER"', '"${DIALSTATUS}" = "WRONG"'],
    'unexpected busy failover' => ['Goto(primary_result)', 'GotoIf($["${DIALSTATUS}" = "BUSY"]?failover:primary_result)'],
    'arbitrary routing' => ['Goto(primary_result)', 'Goto(from-internal,999,1)'],
    'changed recursion guard' => ['Set(__FC_ACTIVE=1)', 'Set(FC_ACTIVE=0)'],
    'changed primary timeout' => ['Set(TRUNK_RING_TIMER=45)', 'Set(TRUNK_RING_TIMER=180)'],
    'missing primary outcome handler' => ['Progress()', 'NoOp(missing handler)'],
];
foreach ($mutations as $name => [$search, $replacement]) {
    $assertSame(true, str_contains($activeCallOutput, $search), 'Mutation fixture did not contain ' . $name);
    $service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = str_replace($search, $replacement, $activeCallOutput);
    $assertSame('Configuration Error', $method->invoke($module, $control)['label'], 'Invalid active dialplan verified: ' . $name);
}
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = $activeCallOutput . "\n999. Invalid-App() [extensions_custom.conf:1]";
$assertSame('Configuration Error', $method->invoke($module, $control)['label'], 'Unrecognised numbered instruction was silently ignored');
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = str_replace('1. GotoIf', '2. GotoIf', $activeCallOutput);
$assertSame('Configuration Error', $method->invoke($module, $control)['label'], 'Duplicate or missing priorities incorrectly verified');
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['Output'] = $activeCallOutput;
$service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['data'] = explode("\n", $activeCallOutput);
$assertSame('Control Enabled', $method->invoke($module, $control)['label'], 'AMI data-array output did not verify');
unset($service->astman->responses['dialplan show ' . $id . '@app-failovercontrol']['data']);

foreach ([0, 1] as $enabled) {
    foreach (range(0, 7) as $mask) {
        if ($enabled === 1 && $mask === 0) {
            continue;
        }
        $matrixId = $repository->save([
            'name' => 'Status combination ' . $enabled . '-' . $mask,
            'primary_trunk_id' => '3',
            'outbound_route_id' => '7',
            'failover_number' => '07876838629',
            'enabled' => (string) $enabled,
            'on_unavailable' => (string) (int) (bool) ($mask & 1),
            'on_busy' => (string) (int) (bool) ($mask & 2),
            'on_no_answer' => (string) (int) (bool) ($mask & 4),
            'ring_timeout' => '0',
        ]);
        $matrixControl = $repository->find($matrixId);
        $generate($matrixId);
        $assertSame($enabled ? 'Control Enabled' : 'Control Disabled', $method->invoke($module, $matrixControl)['label'], 'Generated condition combination failed verification: ' . $enabled . '-' . $mask);
        $matrixKey = 'dialplan show ' . $matrixId . '@app-failovercontrol';
        foreach ([
            'Goto(primary_result)' => 'Goto(ext-trunk,5,1)',
            'Set(__FC_ACTIVE=1)' => 'Goto(from-internal,999,1)',
        ] as $search => $replacement) {
            $savedOutput = $service->astman->responses[$matrixKey]['Output'];
            $service->astman->responses[$matrixKey]['Output'] = str_replace($search, $replacement, $savedOutput);
            $assertSame('Configuration Error', $method->invoke($module, $matrixControl)['label'], 'Unexpected transfer verified for combination ' . $enabled . '-' . $mask);
            $service->astman->responses[$matrixKey]['Output'] = $savedOutput;
        }
        $repository->delete($matrixId);
    }
}

$noConditions = $control;
$noConditions['on_unavailable'] = '0';
$noConditions['on_busy'] = '0';
$noConditions['on_no_answer'] = '0';
$status = $method->invoke($module, $noConditions);
$assertSame('Configuration Error', $status['label'], 'Enabled configuration without failure conditions must not verify');

$service->core->routeName = 'Changed route';
$status = $method->invoke($module, $control);
$assertSame('Reload Required', $status['label'], 'Core route drift should require reload');
$service->core->routeName = 'UK failover';

$service->astman->responses['dialplan show fc-status-' . $id . '@app-failovercontrol']['Output'] = 'NoOp(FAILOVERCONTROL:' . $id . ':stale-fingerprint)';
$status = $method->invoke($module, $control);
$assertSame('Reload Required', $status['label'], 'Stale active fingerprint should require reload');

$service->astman->responses['dialplan show fc-status-' . $id . '@app-failovercontrol']['Output'] = 'NoOp(FAILOVERCONTROL:' . $id . ':' . $fingerprint . ')';
$service->astman->responses['dialplan show outrt-7']['Output'] = "There is no existence of 'outrt-7' context\n";
$status = $method->invoke($module, $control);
$assertSame('Reload Required', $status['label'], 'Missing active outbound route should require reload');

$service->astman->connected = false;
$status = $method->invoke($module, $control);
$assertSame('Unknown', $status['label'], 'Unavailable AMI must not report a verified state');

$service->astman->connected = true;
$repository->save([
    'id' => $id,
    'name' => 'Main Office',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '7',
    'failover_number' => '07876838629',
    'enabled' => '0',
    'on_unavailable' => '1',
    'ring_timeout' => '45',
]);
$disabled = $repository->find($id);
$generate($id);
$assertSame('Control Disabled', $method->invoke($module, $disabled)['label'], 'Disabled state should verify primary-only active dialplan');
$disabledStatus = $method->invoke($module, $disabled);
$assertSame(true, str_contains($disabledStatus['detail'], 'Inbound route usage is not verified'), 'Status did not distinguish control verification from inbound routing');

$trunkControlId = $repository->save([
    'name' => 'Trunk Failover',
    'primary_trunk_id' => '3',
    'failover_type' => 'trunk',
    'failover_trunk_id' => '5',
    'enabled' => '1',
    'on_busy' => '1',
    'ring_timeout' => '45',
]);
$trunkControl = $repository->find($trunkControlId);
$service->astman->responses = [
    'dialplan show 3@ext-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'ext-trunk' created by 'pbx_config' ]\n3 => 1. Set(TDIAL_SUFFIX=@primary-pbx)"],
    'dialplan show macro-dialout-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'macro-dialout-trunk' created by 'pbx_config' ]\n"],
    'dialplan show 5@ext-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'ext-trunk' created by 'pbx_config' ]\n5 => 1. Set(TDIAL_SUFFIX=@failover-pbx)"],
];
$generate($trunkControlId);
$assertSame('Control Enabled', $method->invoke($module, $trunkControl)['label'], 'Native failover trunk without primary-route membership did not verify');
$service->core->missingTrunks = [5];
$assertSame('Configuration Error', $method->invoke($module, $trunkControl)['label'], 'Deleted failover trunk incorrectly verified');
$service->core->missingTrunks = [];

$miscControlId = $repository->save([
    'name' => 'Misc Failover',
    'primary_trunk_id' => '3',
    'failover_type' => 'misc_destination',
    'failover_misc_destination_id' => '9',
    'enabled' => '1',
    'on_busy' => '1',
    'ring_timeout' => '45',
]);
$miscControl = $repository->find($miscControlId);
$service->astman->responses = [
    'dialplan show 3@ext-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'ext-trunk' created by 'pbx_config' ]\n3 => 1. Set(TDIAL_SUFFIX=@primary-pbx)"],
    'dialplan show macro-dialout-trunk' => ['Response' => 'Follows', 'Output' => "[ Context 'macro-dialout-trunk' created by 'pbx_config' ]\n"],
    'dialplan show 9@ext-miscdests' => ['Response' => 'Follows', 'Output' => "[ Context 'ext-miscdests' created by 'pbx_config' ]\n9 => 1. NoOp(MiscDest: Test Misc) 2. Goto(from-internal,601,1)"],
];
$generate($miscControlId);
$assertSame('Control Enabled', $method->invoke($module, $miscControl)['label'], 'Native Miscellaneous Destination did not verify against active dialplan');
$service->astman->responses['dialplan show 9@ext-miscdests']['Output'] = str_replace('from-internal,601,1', 'from-internal,602,1', $service->astman->responses['dialplan show 9@ext-miscdests']['Output']);
$assertSame('Reload Required', $method->invoke($module, $miscControl)['label'], 'Stale active Miscellaneous Destination target incorrectly verified');
$service->miscDestinations->missing = true;
$assertSame('Configuration Error', $method->invoke($module, $miscControl)['label'], 'Deleted Miscellaneous Destination incorrectly verified');
$service->miscDestinations->missing = false;

$validateMethod = new ReflectionMethod($module, 'validateEnabledFailover');
$validateMethod->setAccessible(true);
$sameTrunkRejected = false;
try {
    $validateMethod->invoke($module, [
        'enabled' => '1',
        'primary_trunk_id' => '3',
        'failover_type' => 'trunk',
        'failover_destination_id' => '3',
        'on_busy' => '1',
    ]);
} catch (ReflectionException|RuntimeException $exception) {
    $sameTrunkRejected = true;
}
$assertSame(true, $sameTrunkRejected, 'Using the primary trunk as its own failover destination was accepted');

$service->Core()->dids = [['extension' => '07876838629']];
$miscLoopRejected = false;
try {
    $validateMethod->invoke($module, [
        'enabled' => '1',
        'primary_trunk_id' => '3',
        'failover_type' => 'misc_destination',
        'failover_destination_id' => '10',
        'on_busy' => '1',
    ]);
} catch (ReflectionException|RuntimeException $exception) {
    $miscLoopRejected = true;
}
$assertSame(true, $miscLoopRejected, 'Miscellaneous Destination targeting a local DID was accepted');

echo "Failover status contracts passed\n";
