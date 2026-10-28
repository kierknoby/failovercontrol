<?php
declare(strict_types=1);

namespace FailoverControl;

final class FailoverPolicy
{
    public const MODULE_VERSION = '0.2.1';

    public static function normaliseUkTelephoneNumber(string $number): ?string
    {
        $number = preg_replace('/[\s().-]/', '', trim($number));
        if (!is_string($number) || !preg_match('/^\+?\d+$/', $number)) {
            return null;
        }

        if (str_starts_with($number, '+44')) {
            $national = substr($number, 3);
        } elseif (str_starts_with($number, '0044')) {
            $national = substr($number, 4);
        } elseif (str_starts_with($number, '44')) {
            $national = substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $national = substr($number, 1);
        } else {
            return null;
        }

        if (!preg_match('/^(?:1\d{8,9}|2\d{9}|7[1-57-9]\d{8})$/', $national)) {
            return null;
        }

        return '0' . $national;
    }

    public static function shouldFailover(string $dialStatus, ?string $technologyCause, array $settings, ?int $hangupCause = null, bool $ringObserved = false): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }

        $dialStatus = strtoupper($dialStatus);
        $conditions = self::selectedFailureConditions($settings);

        if (in_array('unavailable', $conditions, true)) {
            if (in_array($dialStatus, ['CHANUNAVAIL', 'CONGESTION', 'NOANSWER'], true) && is_string($technologyCause)) {
                if (preg_match('/\bSIP 408\b/i', $technologyCause) === 1) {
                    return !$ringObserved || (in_array('no_answer', $conditions, true) && $dialStatus === 'NOANSWER');
                }
                if (preg_match('/\bSIP (503|504)\b/i', $technologyCause) === 1) {
                    return true;
                }
            }
            if ($dialStatus === 'NOANSWER' && $hangupCause === 18 && !$ringObserved) {
                return true;
            }
            if ($dialStatus === 'CHANUNAVAIL' && in_array($hangupCause, [20, 38, 41], true)) {
                return true;
            }
            if ($dialStatus === 'CONGESTION' && in_array($hangupCause, [38, 41, 42, 102], true)) {
                return true;
            }
        }

        if (in_array('busy', $conditions, true) && $dialStatus === 'BUSY') {
            return true;
        }

        return in_array('no_answer', $conditions, true) && $dialStatus === 'NOANSWER';
    }

    public static function fingerprint(array $control, array $runtimeConfiguration = []): string
    {
        $fields = [
            'id', 'name', 'description', 'primary_trunk_id', 'outbound_route_id',
            'failover_type', 'failover_destination_id', 'failover_number', 'enabled', 'on_unavailable', 'on_busy',
            'on_no_answer', 'ring_timeout',
        ];
        $configuration = [];
        foreach ($fields as $field) {
            $configuration[$field] = (string) ($control[$field] ?? '');
        }

        return hash('sha256', json_encode([
            'module_version' => self::MODULE_VERSION,
            'control' => $configuration,
            'runtime' => self::canonicalise($runtimeConfiguration),
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    public static function effectiveRouteMetadata(array $control, object $core, ?array $failoverDestination = null): array
    {
        $primary = !empty($control['primary_trunk_id'])
            ? $core->getTrunkByID((int) $control['primary_trunk_id'])
            : false;
        $primaryDialRules = !empty($control['primary_trunk_id'])
            ? $core->getTrunkDialRulesByID((int) $control['primary_trunk_id'])
            : [];
        $failoverType = (string) ($control['failover_type'] ?? 'number');
        $routeId = $failoverType === 'number' ? (int) ($control['outbound_route_id'] ?? 0) : 0;
        $route = null;
        foreach ($core->getAllRoutes() as $candidate) {
            if ((int) ($candidate['route_id'] ?? 0) === $routeId) {
                $route = $candidate;
                break;
            }
        }

        $patterns = $route !== null ? $core->getRoutePatternsByID($routeId) : [];
        $trunkIds = $route !== null ? $core->getRouteTrunksByID($routeId) : [];
        $trunks = [];
        foreach (is_array($trunkIds) ? $trunkIds : [] as $trunkId) {
            $trunk = $core->getTrunkByID((int) $trunkId);
            if (is_array($trunk)) {
                $trunks[] = [
                    'trunkid' => $trunk['trunkid'] ?? $trunkId,
                    'tech' => $trunk['tech'] ?? '',
                    'channelid' => $trunk['channelid'] ?? '',
                    'disabled' => $trunk['disabled'] ?? '',
                    'maxchans' => $trunk['maxchans'] ?? '',
                    'continue' => $trunk['continue'] ?? '',
                ];
            }
        }

        return [
            'primary' => is_array($primary) ? [
                'trunkid' => $primary['trunkid'] ?? '',
                'tech' => $primary['tech'] ?? '',
                'channelid' => $primary['channelid'] ?? '',
                'disabled' => $primary['disabled'] ?? '',
                'dialoutprefix' => $primary['dialoutprefix'] ?? '',
                'outcid' => $primary['outcid'] ?? '',
                'keepcid' => $primary['keepcid'] ?? '',
                'maxchans' => $primary['maxchans'] ?? '',
                'dialopts' => $primary['dialopts'] ?? '',
            ] : null,
            'primary_dial_rules' => is_array($primaryDialRules) ? $primaryDialRules : [],
            'route' => $route,
            'patterns' => is_array($patterns) ? $patterns : [],
            'trunks' => $trunks,
            'failover_destination' => self::canonicalise($failoverDestination),
        ];
    }

    private static function canonicalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalise($item);
        }
        return $value;
    }

    public static function matchesAsteriskPattern(string $number, string $pattern): bool
    {
        if ($number === '' || preg_match('/^\+?\d+$/', $number) !== 1) {
            return false;
        }

        $pattern = ltrim($pattern, '_');
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === 'X') {
                $regex .= '[0-9]';
            } elseif ($character === 'Z') {
                $regex .= '[1-9]';
            } elseif ($character === 'N') {
                $regex .= '[2-9]';
            } elseif ($character === '.') {
                $regex .= '[0-9]+';
            } elseif ($character === '!') {
                $regex .= '[0-9]*';
            } elseif ($character === '[') {
                $close = strpos($pattern, ']', $index + 1);
                if ($close === false) {
                    return false;
                }
                $range = substr($pattern, $index + 1, $close - $index - 1);
                if ($range === '' || preg_match('/^[0-9-]+$/', $range) !== 1) {
                    return false;
                }
                $regex .= '[' . $range . ']';
                $index = $close;
            } elseif (ctype_digit($character)) {
                $regex .= $character;
            } else {
                return false;
            }
        }

        return preg_match('/^' . $regex . '$/', $number) === 1;
    }

    public static function routePreservesUkDestination(int $routeId, string $number, object $core): bool
    {
        $canonicalNumber = self::normaliseUkTelephoneNumber($number);
        if ($canonicalNumber === null) {
            return false;
        }

        $routeNumbers = [];
        foreach ($core->getRoutePatternsByID($routeId) as $pattern) {
            if (!empty($pattern['match_cid'])) {
                continue;
            }
            $dialPattern = (string) ($pattern['match_pattern_prefix'] ?? '') . (string) ($pattern['match_pattern_pass'] ?? '');
            if (!self::matchesAsteriskPattern($canonicalNumber, $dialPattern)) {
                continue;
            }
            $prefix = (string) ($pattern['match_pattern_prefix'] ?? '');
            $offset = strlen(preg_replace('/\[[^\]]*\]/', 'X', $prefix) ?? $prefix);
            $routeNumber = (string) ($pattern['prepend_digits'] ?? '') . substr($canonicalNumber, $offset);
            if (self::normaliseUkTelephoneNumber($routeNumber) !== $canonicalNumber) {
                return false;
            }
            $routeNumbers[] = $routeNumber;
        }
        if (!$routeNumbers) {
            return false;
        }

        $trunkIds = $core->getRouteTrunksByID($routeId);
        $enabledTrunkFound = false;
        foreach (is_array($trunkIds) ? $trunkIds : [] as $trunkId) {
            $trunk = $core->getTrunkByID((int) $trunkId);
            if (!is_array($trunk) || self::isTrunkDisabled($trunk['disabled'] ?? '')) {
                continue;
            }
            $enabledTrunkFound = true;
            foreach ($routeNumbers as $routeNumber) {
                $dialRuleMatched = false;
                foreach ($core->getTrunkDialRulesByID((int) $trunkId) as $dialRule) {
                    if (!empty($dialRule['match_cid'])) {
                        continue;
                    }
                    $dialPattern = (string) ($dialRule['match_pattern_prefix'] ?? '') . (string) ($dialRule['match_pattern_pass'] ?? '');
                    if (!self::matchesAsteriskPattern($routeNumber, $dialPattern)) {
                        continue;
                    }
                    $dialRuleMatched = true;
                    $prefix = (string) ($dialRule['match_pattern_prefix'] ?? '');
                    $offset = strlen(preg_replace('/\[[^\]]*\]/', 'X', $prefix) ?? $prefix);
                    $trunkNumber = (string) ($dialRule['prepend_digits'] ?? '') . substr($routeNumber, $offset);
                    $outboundPrefix = (string) ($trunk['dialoutprefix'] ?? '');
                    if (self::normaliseUkTelephoneNumber($outboundPrefix . $trunkNumber) !== $canonicalNumber) {
                        return false;
                    }
                    break;
                }
                if (!$dialRuleMatched && self::normaliseUkTelephoneNumber((string) ($trunk['dialoutprefix'] ?? '') . $routeNumber) !== $canonicalNumber) {
                    return false;
                }
            }
        }

        return $enabledTrunkFound;
    }

    public static function isTrunkDisabled($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true'], true);
    }

    public static function hasFailureCondition(array $control): bool
    {
        return self::selectedFailureConditions($control) !== [];
    }

    public static function selectedFailureConditions(array $control): array
    {
        $conditions = [];
        if (!empty($control['on_unavailable'])) {
            $conditions[] = 'unavailable';
        }
        if (!empty($control['on_busy'])) {
            $conditions[] = 'busy';
        }
        if (!empty($control['on_no_answer'])) {
            $conditions[] = 'no_answer';
        }
        return $conditions;
    }
}