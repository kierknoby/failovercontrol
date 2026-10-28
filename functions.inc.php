<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

require_once __DIR__ . '/src/FailoverPolicy.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Repository.php';

function failovercontrol_destinations(): ?array
{
    try {
        $controls = (new \FreePBX\modules\Failovercontrol\Repository(\FreePBX::Database()))->all();
    } catch (\Throwable $exception) {
        error_log('Failover Control could not register its destinations: ' . $exception->getMessage());
        return null;
    }

    $destinations = [];
    foreach ($controls as $control) {
        $destinations[] = [
            'destination' => 'app-failovercontrol,' . $control['id'] . ',1',
            'description' => '(' . $control['id'] . ') ' . $control['name'],
        ];
    }

    return $destinations ?: null;
}

function failovercontrol_getdest(string $id): array
{
    return ['app-failovercontrol,' . $id . ',1'];
}

function failovercontrol_getdestinfo(string $destination)
{
    if (!preg_match('/^app-failovercontrol,([a-f0-9-]{36}),1$/i', trim($destination), $matches)) {
        return false;
    }

    $control = (new \FreePBX\modules\Failovercontrol\Repository(\FreePBX::Database()))->find($matches[1]);
    if ($control === null) {
        return [];
    }

    return [
        'description' => sprintf(_('Failover Control: %s'), $control['name']),
        'edit_url' => 'config.php?display=failovercontrol&view=form&id=' . urlencode($control['id']),
    ];
}

function failovercontrol_check_destinations($destinations = true): ?array
{
    $controls = (new \FreePBX\modules\Failovercontrol\Repository(\FreePBX::Database()))->all();
    $requested = $destinations === true ? null : (array) $destinations;
    $used = [];

    foreach ($controls as $control) {
        $type = (string) ($control['failover_type'] ?? 'number');
        $destinationId = (string) ($control['failover_destination_id'] ?? '');
        if ($type === 'trunk' && ctype_digit($destinationId) && (int) $destinationId > 0) {
            $destination = 'ext-trunk,' . (int) $destinationId . ',1';
        } elseif ($type === 'misc_destination' && ctype_digit($destinationId) && (int) $destinationId > 0) {
            $destination = 'ext-miscdests,' . (int) $destinationId . ',1';
        } else {
            continue;
        }

        if ($requested !== null && !in_array($destination, $requested, true)) {
            continue;
        }
        $used[] = [
            'dest' => $destination,
            'description' => sprintf(_('Failover Control: %s'), $control['name']),
            'edit_url' => 'config.php?display=failovercontrol&view=form&id=' . urlencode($control['id']),
        ];
    }

    return $used ?: null;
}

function failovercontrol_get_config(string $engine): void
{
    global $ext;
    if ($engine !== 'asterisk') {
        return;
    }

    try {
        $controls = (new \FreePBX\modules\Failovercontrol\Repository(\FreePBX::Database()))->all();
    } catch (\Throwable $exception) {
        error_log('Failover Control could not load its configuration: ' . $exception->getMessage());
        return;
    }

    $context = 'app-failovercontrol';
    foreach ($controls as $control) {
        $id = $control['id'];
        $trunk = $control['primary_trunk_id'] ? \FreePBX::Core()->getTrunkByID((int) $control['primary_trunk_id']) : false;
        $endpoint = is_array($trunk) && strtolower((string) ($trunk['tech'] ?? '')) === 'pjsip'
            ? (string) ($trunk['channelid'] ?? '')
            : '';
        if ($endpoint === '' || !preg_match('/^[A-Za-z0-9_.!:+-]+$/', $endpoint)
            || \FailoverControl\FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
            $ext->add($context, $id, '', new ext_noop('Failover Control: primary PJSIP trunk is not configured'));
            $ext->add($context, $id, '', new ext_hangup(3));
            continue;
        }

        $statusExtension = 'fc-status-' . $id;
        $failoverType = (string) ($control['failover_type'] ?? 'number');
        $failoverDestinationId = (string) ($control['failover_destination_id'] ?? '');
        $failoverDestination = null;
        $failoverTarget = null;
        $routeReady = false;
        if ($failoverType === 'number') {
            $selectedRoute = null;
            foreach (\FreePBX::Core()->getAllRoutes() as $route) {
                if ((int) ($route['route_id'] ?? 0) === (int) ($control['outbound_route_id'] ?? 0)) {
                    $selectedRoute = $route;
                    break;
                }
            }
            $routeReady = is_array($selectedRoute)
                && trim((string) ($selectedRoute['password'] ?? '')) === ''
                && empty($selectedRoute['time_group_id'])
                && empty($selectedRoute['emergency_route'])
                && trim((string) ($selectedRoute['dest'] ?? '')) === ''
                && \FailoverControl\FailoverPolicy::routePreservesUkDestination((int) $control['outbound_route_id'], (string) $control['failover_number'], \FreePBX::Core());
            if ($routeReady) {
                $numberMatchesRoute = false;
                foreach (\FreePBX::Core()->getRoutePatternsByID((int) $control['outbound_route_id']) as $pattern) {
                    if (!empty($pattern['match_cid'])) {
                        continue;
                    }
                    $routePattern = (string) ($pattern['match_pattern_prefix'] ?? '') . (string) ($pattern['match_pattern_pass'] ?? '');
                    if (\FailoverControl\FailoverPolicy::matchesAsteriskPattern((string) $control['failover_number'], $routePattern)) {
                        $numberMatchesRoute = true;
                        break;
                    }
                }
                $routeReady = $numberMatchesRoute;
            }
            if ($routeReady) {
                $routeReady = false;
                foreach (\FreePBX::Core()->getRouteTrunksByID((int) $control['outbound_route_id']) as $routeTrunkId) {
                    $routeTrunk = \FreePBX::Core()->getTrunkByID((int) $routeTrunkId);
                    if (is_array($routeTrunk) && !\FailoverControl\FailoverPolicy::isTrunkDisabled($routeTrunk['disabled'] ?? '')) {
                        $routeReady = true;
                        break;
                    }
                }
            }
        } elseif ($failoverType === 'trunk' && ctype_digit($failoverDestinationId) && (int) $failoverDestinationId > 0) {
            $destinationTrunk = \FreePBX::Core()->getTrunkByID((int) $failoverDestinationId);
            if (is_array($destinationTrunk)
                && strtolower((string) ($destinationTrunk['tech'] ?? '')) !== 'enum'
                && !\FailoverControl\FailoverPolicy::isTrunkDisabled($destinationTrunk['disabled'] ?? '')
                && (int) $failoverDestinationId !== (int) $control['primary_trunk_id']) {
                $failoverDestination = [
                    'trunkid' => $destinationTrunk['trunkid'] ?? $failoverDestinationId,
                    'tech' => $destinationTrunk['tech'] ?? '',
                    'channelid' => $destinationTrunk['channelid'] ?? '',
                    'disabled' => $destinationTrunk['disabled'] ?? '',
                    'dialoutprefix' => $destinationTrunk['dialoutprefix'] ?? '',
                    'outcid' => $destinationTrunk['outcid'] ?? '',
                    'keepcid' => $destinationTrunk['keepcid'] ?? '',
                    'maxchans' => $destinationTrunk['maxchans'] ?? '',
                    'dialopts' => $destinationTrunk['dialopts'] ?? '',
                ];
                $failoverTarget = ['context' => 'ext-trunk', 'extension' => (int) $failoverDestinationId];
            }
        } elseif ($failoverType === 'misc_destination' && ctype_digit($failoverDestinationId) && (int) $failoverDestinationId > 0) {
            $registeredDestinations = \FreePBX::Destinations()->getModuleDestinations('miscdests');
            $destinationString = 'ext-miscdests,' . (int) $failoverDestinationId . ',1';
            $isRegistered = false;
            foreach (is_array($registeredDestinations) ? $registeredDestinations : [] as $registeredDestination) {
                if ((string) ($registeredDestination['destination'] ?? '') === $destinationString) {
                    $isRegistered = true;
                    break;
                }
            }
            $miscDestination = null;
            if ($isRegistered) {
                $miscDestinationResult = \FreePBX::Miscdests()->get((int) $failoverDestinationId);
                $miscDestination = is_array($miscDestinationResult) && isset($miscDestinationResult['id'])
                    ? $miscDestinationResult
                    : ($miscDestinationResult[0] ?? null);
            }
            if (is_array($miscDestination) && (int) ($miscDestination['id'] ?? 0) === (int) $failoverDestinationId
                && trim((string) ($miscDestination['destdial'] ?? '')) !== '') {
                $failoverDestination = [
                    'id' => $miscDestination['id'],
                    'description' => $miscDestination['description'] ?? '',
                    'destdial' => $miscDestination['destdial'],
                ];
                $failoverTarget = ['context' => 'ext-miscdests', 'extension' => (int) $failoverDestinationId];
            }
        }
        $runtimeConfiguration = \FailoverControl\FailoverPolicy::effectiveRouteMetadata(
            $control,
            \FreePBX::Core(),
            $failoverDestination
        );
        $ext->add($context, $statusExtension, '', new ext_noop('FAILOVERCONTROL:' . $id . ':' . \FailoverControl\FailoverPolicy::fingerprint($control, $runtimeConfiguration)));
        $ext->add($context, $statusExtension, '', new ext_hangup());
        $ext->add($context, $id, '', new ext_gotoif('$["${FC_ACTIVE}" = "1"]', 'loop_blocked'));
        $ext->add($context, $id, '', new ext_setvar('__FC_ACTIVE', '1'));
        $ext->add($context, $id, '', new ext_setvar('FC_DID', '${FILTER(0-9+,${FROM_DID})}'));
        $ext->add($context, $id, '', new ext_gotoif('$[${REGEX("^[+]?[0-9]+$" ${FROM_DID})} != 1]', 'invalid_did'));
        $ext->add($context, $id, '', new ext_gotoif('$["${FC_DID}" != "${FROM_DID}"]', 'invalid_did'));
        $timeout = (int) $control['ring_timeout'];
        if ($timeout > 0) {
            $ext->add($context, $id, '', new ext_setvar('TRUNK_RING_TIMER', (string) $timeout));
        }
        $ext->add($context, $id, '', new ext_setvar('DIALSTATUS', ''));
        $ext->add($context, $id, '', new extension('HangupCauseClear()'));
        $ext->add($context, $id, '', new ext_macro('dialout-trunk', (int) $control['primary_trunk_id'] . ',${FC_DID},,on'));
        $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "ANSWER"]', 'done'));

        $failoverGenerated = (int) $control['enabled'] === 1
            && ($failoverType === 'number' ? (string) $control['failover_number'] !== '' && $routeReady : $failoverTarget !== null)
            && \FailoverControl\FailoverPolicy::hasFailureCondition($control);
        if ($failoverGenerated) {
            foreach (\FailoverControl\FailoverPolicy::selectedFailureConditions($control) as $condition) {
                if ($condition === 'unavailable') {
                    $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "CHANUNAVAIL"]', 'test_unavailable'));
                    $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "CONGESTION"]', 'test_unavailable'));
                    $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER"]', 'test_unavailable'));
                } elseif ($condition === 'busy') {
                    $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "BUSY"]', 'failover'));
                } elseif ($condition === 'no_answer') {
                    if (!in_array('unavailable', \FailoverControl\FailoverPolicy::selectedFailureConditions($control), true)) {
                        $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER"]', 'failover'));
                    }
                }
            }
            $ext->add($context, $id, '', new ext_goto('primary_result'));
            if (in_array('unavailable', \FailoverControl\FailoverPolicy::selectedFailureConditions($control), true)) {
                $ext->add($context, $id, 'test_unavailable', new ext_setvar('FC_PEERS', '${HANGUPCAUSE_KEYS()}'));
                $ext->add($context, $id, '', new ext_setvar('FC_PEER', '${CUT(FC_PEERS,\\,,1)}'));
                $ext->add($context, $id, '', new ext_gotoif('$["${FC_PEER}" = ""]', 'cause_only'));
                $ext->add($context, $id, '', new ext_setvar('FC_TECH_CAUSE', '${HANGUPCAUSE(${FC_PEER},tech)}'));
                $ext->add($context, $id, '', new ext_gotoif('${REGEX("SIP 408" ${FC_TECH_CAUSE})}', 'test_timeout'));
                $ext->add($context, $id, '', new ext_gotoif('${REGEX("SIP (503|504)" ${FC_TECH_CAUSE})}', 'failover'));
                $ext->add($context, $id, 'cause_only', new ext_setvar('FC_AST_CAUSE', '${HANGUPCAUSE}'));
                $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER" & "${FC_AST_CAUSE}" = "18" & "${RINGTIME}" = ""]', 'failover'));
                $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "CHANUNAVAIL" & "${FC_AST_CAUSE}" = "20"]', 'failover'));
                $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "CHANUNAVAIL" & ("${FC_AST_CAUSE}" = "38" | "${FC_AST_CAUSE}" = "41")]','failover'));
                $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "CONGESTION" & ("${FC_AST_CAUSE}" = "38" | "${FC_AST_CAUSE}" = "41" | "${FC_AST_CAUSE}" = "42" | "${FC_AST_CAUSE}" = "102")]','failover'));
                if (in_array('no_answer', \FailoverControl\FailoverPolicy::selectedFailureConditions($control), true)) {
                    $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER"]', 'failover'));
                }
                $ext->add($context, $id, '', new ext_goto('primary_result'));
                $noAnswerTarget = in_array('no_answer', \FailoverControl\FailoverPolicy::selectedFailureConditions($control), true)
                    ? 'failover'
                    : 'primary_result';
                $ext->add($context, $id, 'test_timeout', new ext_gotoif('$["${RINGTIME}" = ""]', 'failover', 'test_timeout_after_ringing'));
                $ext->add($context, $id, 'test_timeout_after_ringing', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER"]', $noAnswerTarget, 'primary_result'));
            }
            $ext->add($context, $id, 'failover', new ext_noop('FAILOVERCONTROL:' . $id . ':failover-attempt:DID:${FC_DID}:STATUS:${DIALSTATUS}:CAUSE:${FC_TECH_CAUSE}:UID:${UNIQUEID}'));
            if ($failoverType === 'number') {
                $ext->add($context, $id, '', new ext_dial('Local/' . $control['failover_number'] . '@outrt-' . (int) $control['outbound_route_id'] . '/n', ',g'));
                $ext->add($context, $id, '', new ext_gotoif('$["${DIALSTATUS}" = "ANSWER"]', 'done'));
                $ext->add($context, $id, '', new ext_noop('FAILOVERCONTROL:' . $id . ':fallback-result:DID:${FC_DID}:STATUS:${DIALSTATUS}:UID:${UNIQUEID}'));
                $ext->add($context, $id, '', new ext_goto('generic_failure'));
            } else {
                $ext->add($context, $id, '', new ext_goto('1', (string) $failoverTarget['extension'], (string) $failoverTarget['context']));
            }
        } else {
            $ext->add($context, $id, '', new ext_goto('primary_result'));
        }

        $ext->add($context, $id, 'invalid_did', new ext_hangup(21));
        $ext->add($context, $id, 'done', new ext_hangup());
        $ext->add($context, $id, 'loop_blocked', new ext_noop('FAILOVERCONTROL:' . $id . ':recursive-call-blocked:UID:${UNIQUEID}'));
        $ext->add($context, $id, '', new ext_hangup(21));
        $ext->add($context, $id, 'primary_result', new ext_gotoif('$["${DIALSTATUS}" = "BUSY"]', 'macro-dialout-trunk,s-BUSY,1', 'check_noanswer'));
        $ext->add($context, $id, 'check_noanswer', new ext_gotoif('$["${DIALSTATUS}" = "NOANSWER"]', 'macro-dialout-trunk,s-NOANSWER,1', 'generic_failure'));
        $ext->add($context, $id, 'generic_failure', new extension('Progress()'));
        $ext->add($context, $id, '', new ext_macro('outisbusy'));
        $ext->add($context, $id, '', new extension('Congestion(20)'));
        $ext->add($context, $id, '', new ext_hangup());
    }
}