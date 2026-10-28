<?php
declare(strict_types=1);

namespace FreePBX\modules;

require_once __DIR__ . '/src/FailoverPolicy.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Repository.php';

use FreePBX\modules\Failovercontrol\Repository;
use FreePBX\modules\Failovercontrol\Schema;
use FailoverControl\FailoverPolicy;
use PDO;
use RuntimeException;
use Throwable;

class Failovercontrol implements \BMO
{
    private const CSRF_SESSION_KEY = 'failovercontrol_csrf_token';

    private $FreePBX;

    public function __construct($freepbx = null)
    {
        if ($freepbx === null) {
            throw new RuntimeException('Not given a FreePBX object.');
        }
        $this->FreePBX = $freepbx;
    }

    public function install(): void
    {
        Schema::install($this->db());
    }

    public function uninstall()
    {
        if ($this->hasDestinationReferences()) {
            return false;
        }
        $this->db()->exec('DROP TABLE IF EXISTS failovercontrol_controls');
        return true;
    }

    public function disable(): bool
    {
        return !$this->hasDestinationReferences();
    }

    public function doConfigPageInit($page): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['save', 'delete', 'toggle'], true)) {
            return;
        }
        if (!$this->validateCsrfToken()) {
            $this->setNotice('The security token was invalid. Reload this page and try again.', 'danger');
            return;
        }

        try {
            if ($action === 'save') {
                $this->saveControl($_POST);
                needreload();
                $this->setNotice('Failover Control saved. Apply Config to activate the change.', 'success');
            } elseif ($action === 'toggle') {
                $this->toggleControl((string) ($_POST['id'] ?? ''), (string) ($_POST['enabled'] ?? '0') === '1');
                needreload();
                $this->setNotice('Failover state saved. Apply Config to activate the change.', 'success');
            } else {
                $this->deleteControl((string) ($_POST['id'] ?? ''));
                needreload();
                $this->setNotice('Failover Control deleted. Apply Config to activate the change.', 'success');
            }
        } catch (Throwable $exception) {
            $this->setNotice($exception->getMessage(), 'danger');
        }
    }

    public function getActionBar($request): array
    {
        return [];
    }

    public function showPage(): string
    {
        if (!Schema::isReady($this->db())) {
            return '<div class="alert alert-danger">Failover Control database tables are missing. Reinstall or repair the module.</div>';
        }

        $id = (string) ($_REQUEST['id'] ?? '');
        $control = $id !== '' ? $this->repository()->find($id) : null;
        $formId = $control['id'] ?? ($id !== '' ? $id : Repository::newId());
        $controls = $this->repository()->all();
        $trunkNames = [];
        foreach ($this->pjsipTrunks() as $trunk) {
            $trunkNames[(string) $trunk['trunkid']] = (string) ($trunk['name'] ?: $trunk['channelid']);
        }
        $statuses = [];
        foreach ($controls as $item) {
            $statuses[$item['id']] = $this->configurationStatus($item);
        }

        return load_view(__DIR__ . '/views/main.php', [
            'controls' => $controls,
            'control' => $control,
            'formId' => $formId,
            'trunks' => $this->pjsipTrunks(),
            'failoverTrunks' => $this->failoverTrunks(),
            'miscDestinations' => $this->miscDestinations(),
            'trunkNames' => $trunkNames,
            'routes' => $this->outboundRoutes(),
            'statuses' => $statuses,
            'csrfToken' => $this->createCsrfToken(),
            'notice' => $this->consumeNotice(),
            'status' => $control !== null ? $this->configurationStatus($control) : null,
        ]);
    }

    public function getControlStatuses(): array
    {
        $results = [];
        foreach ($this->repository()->all() as $control) {
            $status = $this->configurationStatus($control);
            $results[] = [
                'id' => $control['id'],
                'name' => $control['name'],
                'enabled' => (int) $control['enabled'] === 1 ? 'yes' : 'no',
                'status' => $status['label'],
                'detail' => $status['detail'],
            ];
        }
        return $results;
    }

    private function saveControl(array $input): void
    {
        $trunkId = trim((string) ($input['primary_trunk_id'] ?? ''));
        if ($trunkId === '') {
            throw new RuntimeException('Select a PJSIP primary trunk.');
        }
        $trunk = $this->FreePBX->Core()->getTrunkByID((int) $trunkId);
        if (!is_array($trunk) || strtolower((string) ($trunk['tech'] ?? '')) !== 'pjsip' || FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
            throw new RuntimeException('The selected primary trunk is not a valid PJSIP trunk.');
        }

        if (!empty($input['enabled'])) {
            $this->validateEnabledFailover($input);
        }

        $this->repository()->save($input, (string) ($input['is_new'] ?? '0') === '1');
    }

    private function toggleControl(string $id, bool $enabled): void
    {
        $control = $this->repository()->find($id);
        if ($control === null) {
            throw new RuntimeException('The selected control no longer exists.');
        }
        if ($enabled) {
            $trunk = $this->FreePBX->Core()->getTrunkByID((int) $control['primary_trunk_id']);
            if (!is_array($trunk) || strtolower((string) ($trunk['tech'] ?? '')) !== 'pjsip' || FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
                throw new RuntimeException('A valid, enabled PJSIP primary trunk is required before enabling failover.');
            }
            $this->validateEnabledFailover($control);
        }
        $control['enabled'] = $enabled ? '1' : '0';
        $this->repository()->save($control);
    }

    private function deleteControl(string $id): void
    {
        $control = $this->repository()->find($id);
        if ($control === null) {
            throw new RuntimeException('The selected control no longer exists.');
        }

        $destination = 'app-failovercontrol,' . $id . ',1';
        $references = $this->FreePBX->Destinations()->getAllInUseDestinations([$destination]);
        if (!empty($references)) {
            $users = [];
            foreach ($references as $module => $items) {
                foreach ($items as $item) {
                    $users[] = (string) ($item['description'] ?? $module);
                }
            }
            throw new RuntimeException('This destination is used by ' . implode(', ', array_unique($users)) . '. Change those destinations before deleting this control.');
        }

        $this->repository()->delete($id);
    }

    private function hasDestinationReferences(): bool
    {
        foreach ($this->repository()->all() as $control) {
            $destination = 'app-failovercontrol,' . $control['id'] . ',1';
            $references = $this->FreePBX->Destinations()->getAllInUseDestinations([$destination]);
            if (!empty($references)) {
                $this->FreePBX->Log()->warning('Failover Control module action refused: destination ' . $control['id'] . ' is still referenced.');
                return true;
            }
        }
        return false;
    }

    private function configurationStatus(array $control): array
    {
        if (empty($control['primary_trunk_id'])) {
            return ['label' => 'Not Configured', 'detail' => 'A PJSIP primary trunk is required.', 'class' => 'warning'];
        }

        $trunk = $this->FreePBX->Core()->getTrunkByID((int) $control['primary_trunk_id']);
        if (!is_array($trunk) || strtolower((string) ($trunk['tech'] ?? '')) !== 'pjsip' || FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
            return ['label' => 'Configuration Error', 'detail' => 'The configured PJSIP trunk is missing.', 'class' => 'danger'];
        }
        if ((int) $control['enabled'] === 1 && !FailoverPolicy::hasFailureCondition($control)) {
            return ['label' => 'Configuration Error', 'detail' => 'Enabled failover has no selected failure condition.', 'class' => 'danger'];
        }
        if ((int) $control['enabled'] === 1) {
            try {
                $this->validateEnabledFailover($control);
            } catch (RuntimeException $exception) {
                return ['label' => 'Configuration Error', 'detail' => $exception->getMessage(), 'class' => 'danger'];
            }
        }

        try {
            if (!$this->FreePBX->astman || !$this->FreePBX->astman->connected()) {
                return ['label' => 'Unknown', 'detail' => 'Asterisk is not reachable through AMI.', 'class' => 'default'];
            }
            $response = $this->FreePBX->astman->Command('dialplan show ' . $control['id'] . '@app-failovercontrol');
            $statusResponse = $this->FreePBX->astman->Command('dialplan show fc-status-' . $control['id'] . '@app-failovercontrol');
            $primaryTrunkResponse = $this->FreePBX->astman->Command('dialplan show ' . (int) $control['primary_trunk_id'] . '@ext-trunk');
            $primaryMacroResponse = $this->FreePBX->astman->Command('dialplan show macro-dialout-trunk');
            $failoverType = (string) ($control['failover_type'] ?? 'number');
            $routeContext = 'outrt-' . (int) $control['outbound_route_id'];
            $routeResponse = (int) $control['enabled'] === 1 && $failoverType === 'number'
                ? $this->FreePBX->astman->Command('dialplan show ' . $routeContext)
                : null;
            $failoverTrunkResponse = (int) $control['enabled'] === 1 && $failoverType === 'trunk'
                ? $this->FreePBX->astman->Command('dialplan show ' . (int) $control['failover_destination_id'] . '@ext-trunk')
                : null;
            $miscDestinationResponse = (int) $control['enabled'] === 1 && $failoverType === 'misc_destination'
                ? $this->FreePBX->astman->Command('dialplan show ' . (int) $control['failover_destination_id'] . '@ext-miscdests')
                : null;
        } catch (Throwable $exception) {
            return ['label' => 'Unknown', 'detail' => 'The active Asterisk dialplan could not be queried.', 'class' => 'default'];
        }

        if (!is_array($response) || !in_array(($response['Response'] ?? ''), ['Success', 'Follows'], true)
            || !is_array($statusResponse) || !in_array(($statusResponse['Response'] ?? ''), ['Success', 'Follows'], true)
            || !is_array($primaryTrunkResponse) || !in_array(($primaryTrunkResponse['Response'] ?? ''), ['Success', 'Follows'], true)
            || !is_array($primaryMacroResponse) || !in_array(($primaryMacroResponse['Response'] ?? ''), ['Success', 'Follows'], true)
            || ((int) $control['enabled'] === 1 && $failoverType === 'number' && (!is_array($routeResponse) || !in_array(($routeResponse['Response'] ?? ''), ['Success', 'Follows'], true)))
            || ((int) $control['enabled'] === 1 && $failoverType === 'trunk' && (!is_array($failoverTrunkResponse) || !in_array(($failoverTrunkResponse['Response'] ?? ''), ['Success', 'Follows'], true)))
            || ((int) $control['enabled'] === 1 && $failoverType === 'misc_destination' && (!is_array($miscDestinationResponse) || !in_array(($miscDestinationResponse['Response'] ?? ''), ['Success', 'Follows'], true)))) {
            return ['label' => 'Unknown', 'detail' => 'Asterisk did not confirm the dialplan query.', 'class' => 'default'];
        }
        $number = $failoverType === 'number'
            ? FailoverPolicy::normaliseUkTelephoneNumber((string) ($control['failover_number'] ?? ''))
            : null;
        $output = $this->amiOutput($response);
        $statusOutput = $this->amiOutput($statusResponse);
        $prefix = 'FAILOVERCONTROL:' . $control['id'] . ':';
        $activeFingerprint = null;
        foreach (preg_split('/\r?\n/', $statusOutput) ?: [] as $line) {
            $position = strpos($line, $prefix);
            if ($position !== false) {
                $activeFingerprint = substr($line, $position + strlen($prefix));
                $activeFingerprint = preg_split('/[\s,)]/', $activeFingerprint, 2)[0] ?? '';
                break;
            }
        }
        if ($activeFingerprint === null) {
            return ['label' => 'Configuration Error', 'detail' => 'The expected Failover Control extension is not present in the active dialplan.', 'class' => 'danger'];
        }
        $runtimeConfiguration = FailoverPolicy::effectiveRouteMetadata(
            $control,
            $this->FreePBX->Core(),
            $this->failoverDestinationMetadata($control)
        );
        if (!hash_equals(FailoverPolicy::fingerprint($control, $runtimeConfiguration), $activeFingerprint)) {
            return ['label' => 'Reload Required', 'detail' => 'Saved settings differ from the active Asterisk dialplan.', 'class' => 'warning'];
        }
        $endpoint = (string) ($trunk['channelid'] ?? '');
        $primaryMacro = 'Gosub(macro-dialout-trunk,s,1(' . (int) $control['primary_trunk_id'] . ',${FC_DID},,on))';
        if ($endpoint === '' || strpos($output, $primaryMacro) === false) {
            return ['label' => 'Configuration Error', 'detail' => 'The active primary call does not use the configured FreePBX trunk dial path.', 'class' => 'danger'];
        }
        foreach ([
            'GotoIf($["${DIALSTATUS}" = "BUSY"]?macro-dialout-trunk,s-BUSY,1:check_noanswer)',
            'GotoIf($["${DIALSTATUS}" = "NOANSWER"]?macro-dialout-trunk,s-NOANSWER,1:generic_failure)',
            'Congestion(20)',
        ] as $primaryFailureHandler) {
            if (strpos($output, $primaryFailureHandler) === false) {
                return ['label' => 'Configuration Error', 'detail' => 'The active primary failure path does not preserve FreePBX call-result handling.', 'class' => 'danger'];
            }
        }
        $activeTrunk = $this->amiOutput($primaryTrunkResponse);
        if (strpos($activeTrunk, "[ Context 'ext-trunk'") === false || strpos($activeTrunk, 'Set(TDIAL_SUFFIX=@' . $endpoint . ')') === false) {
            return ['label' => 'Reload Required', 'detail' => 'The selected PJSIP trunk channel is not present in the active Core dialplan.', 'class' => 'warning'];
        }
        if (strpos($this->amiOutput($primaryMacroResponse), "[ Context 'macro-dialout-trunk'") === false) {
            return ['label' => 'Configuration Error', 'detail' => 'FreePBX Core supervised trunk dialling is not present in the active Asterisk dialplan. A Core trunk dial path must generate this shared macro; the primary trunk itself need not belong to an outbound route.', 'class' => 'danger'];
        }
        if ((int) $control['enabled'] === 1 && !$this->activeFailoverDestinationMatches($output, $control)) {
            return ['label' => 'Configuration Error', 'detail' => 'The active failover destination does not match the saved destination.', 'class' => 'danger'];
        }
        if (!$this->activeFailurePolicyMatches($output, $control)) {
            return ['label' => 'Configuration Error', 'detail' => 'The active dialplan failure branches do not match the saved policy.', 'class' => 'danger'];
        }
        $routeHeading = "[ Context '" . $routeContext . "'";
        if ((int) $control['enabled'] === 1 && $failoverType === 'number' && strpos($this->amiOutput($routeResponse), $routeHeading) === false) {
            return ['label' => 'Reload Required', 'detail' => 'The selected outbound route is not present in the active Asterisk dialplan.', 'class' => 'warning'];
        }
        if ((int) $control['enabled'] === 1 && $failoverType === 'trunk') {
            $failoverTrunk = $this->FreePBX->Core()->getTrunkByID((int) $control['failover_destination_id']);
            $activeFailoverTrunk = $this->amiOutput($failoverTrunkResponse);
            $trunkExtension = (int) $control['failover_destination_id'];
            if (!is_array($failoverTrunk)
                || strpos($activeFailoverTrunk, "[ Context 'ext-trunk'") === false
                || preg_match('/(?:^|\n)\s*[\'"]?' . preg_quote((string) $trunkExtension, '/') . '[\'"]?\s*=>/m', $activeFailoverTrunk) !== 1) {
                return ['label' => 'Reload Required', 'detail' => 'The selected failover trunk is not present in the active Core trunk dialplan.', 'class' => 'warning'];
            }
            if (strtolower((string) ($failoverTrunk['tech'] ?? '')) === 'pjsip'
                && strpos($activeFailoverTrunk, 'Set(TDIAL_SUFFIX=@' . (string) ($failoverTrunk['channelid'] ?? '') . ')') === false) {
                return ['label' => 'Reload Required', 'detail' => 'The selected failover PJSIP endpoint is not present in the active Core trunk dialplan.', 'class' => 'warning'];
            }
        }
        if ((int) $control['enabled'] === 1 && $failoverType === 'misc_destination') {
            $activeMiscDestination = $this->amiOutput($miscDestinationResponse);
            $destinationId = (int) $control['failover_destination_id'];
            $miscDestination = $this->getMiscDestination($destinationId);
            if (!is_array($miscDestination)
                || strpos($activeMiscDestination, "[ Context 'ext-miscdests'") === false
                || preg_match('/(?:^|\n)\s*[\'"]?' . preg_quote((string) $destinationId, '/') . '[\'"]?\s*=>/m', $activeMiscDestination) !== 1
                || strpos($activeMiscDestination, 'Goto(from-internal,' . $miscDestination['destdial'] . ',1)') === false) {
                return ['label' => 'Reload Required', 'detail' => 'The selected Miscellaneous Destination is not present in the active FreePBX dialplan.', 'class' => 'warning'];
            }
        }
        if ((int) $control['enabled'] === 1 && $failoverType === 'number') {
            $activeRoute = $this->amiOutput($routeResponse);
            $expectedPattern = $number !== null
                ? $this->matchingActiveRoutePattern((int) $control['outbound_route_id'], $number)
                : null;
            if ($expectedPattern === null || strpos($activeRoute, $expectedPattern) === false) {
                return ['label' => 'Reload Required', 'detail' => 'The configured failover number pattern is not present in the active outbound route.', 'class' => 'warning'];
            }
            $hasActiveRouteTrunk = false;
            foreach ($this->FreePBX->Core()->getRouteTrunksByID((int) $control['outbound_route_id']) as $routeTrunkId) {
                $routeTrunk = $this->FreePBX->Core()->getTrunkByID((int) $routeTrunkId);
                if (!is_array($routeTrunk) || FailoverPolicy::isTrunkDisabled($routeTrunk['disabled'] ?? '')) {
                    continue;
                }
                if (strpos($activeRoute, 'Gosub(macro-dialout-trunk,s,1(' . (int) $routeTrunkId . ',') !== false) {
                    $hasActiveRouteTrunk = true;
                    break;
                }
            }
            if (!$hasActiveRouteTrunk) {
                return ['label' => 'Reload Required', 'detail' => 'No enabled configured trunk is present in the active outbound route.', 'class' => 'warning'];
            }
        }
        $references = $this->destinationReferences($control);
        $referenceDetail = $references
            ? ' FreePBX destination references: ' . implode(', ', $references) . '.'
            : ' No FreePBX destination references were found; an unused control remains valid.';
        if ((int) $control['enabled'] === 1) {
            return ['label' => 'Control Enabled', 'detail' => 'Control configuration matches saved settings and the active Asterisk dialplan. Inbound route usage is not verified. End-to-end calling has not been tested.' . $referenceDetail, 'class' => 'success'];
        }

        return ['label' => 'Control Disabled', 'detail' => 'Control configuration matches saved settings and the active Asterisk dialplan; primary routing only. Inbound route usage is not verified. End-to-end calling has not been tested.' . $referenceDetail, 'class' => 'default'];
    }

    private function amiOutput(array $response): string
    {
        $output = $response['data'] ?? $response['Output'] ?? '';
        if (is_array($output)) {
            return implode("\n", array_map('strval', $output));
        }
        return is_string($output) ? $output : '';
    }

    private function pjsipTrunks(): array
    {
        $trunks = $this->FreePBX->Core()->listTrunks();
        return array_values(array_filter(is_array($trunks) ? $trunks : [], static function (array $trunk): bool {
            return strtolower((string) ($trunk['tech'] ?? '')) === 'pjsip' && !FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '');
        }));
    }

    private function failoverTrunks(): array
    {
        $trunks = $this->FreePBX->Core()->listTrunks();
        return array_values(array_filter(is_array($trunks) ? $trunks : [], static function (array $trunk): bool {
            return (int) ($trunk['trunkid'] ?? 0) > 0
                && strtolower((string) ($trunk['tech'] ?? '')) !== 'enum'
                && !FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '');
        }));
    }

    private function miscDestinations(): array
    {
        $destinations = $this->FreePBX->Destinations()->getModuleDestinations('miscdests');
        return array_values(array_filter(is_array($destinations) ? $destinations : [], static function (array $destination): bool {
            return preg_match('/^ext-miscdests,[1-9][0-9]*,1$/', (string) ($destination['destination'] ?? '')) === 1;
        }));
    }

    private function validateEnabledFailover(array $control): void
    {
        if (!FailoverPolicy::hasFailureCondition($control)) {
            throw new RuntimeException('Enable at least one failure condition before enabling failover.');
        }

        $type = (string) ($control['failover_type'] ?? 'number');
        if ($type === 'number') {
            $number = FailoverPolicy::normaliseUkTelephoneNumber((string) ($control['failover_number'] ?? ''));
            $routeId = (int) ($control['outbound_route_id'] ?? 0);
            if ($number === null || $routeId < 1 || !$this->outboundRouteReady($routeId, $number)) {
                throw new RuntimeException('Select a UK geographic/mobile number and an outbound route which matches it and has an enabled trunk.');
            }
            if ($this->isLocalInboundNumber($number)) {
                throw new RuntimeException('The failover number matches an inbound DID on this system and could create a routing loop.');
            }
            return;
        }

        $destinationId = $this->selectedFailoverDestinationId($control, $type);
        if ($destinationId === '' || !ctype_digit($destinationId) || (int) $destinationId < 1) {
            throw new RuntimeException('Select an existing failover destination.');
        }
        if ($type === 'trunk') {
            $trunk = $this->FreePBX->Core()->getTrunkByID((int) $destinationId);
            if (!is_array($trunk) || strtolower((string) ($trunk['tech'] ?? '')) === 'enum'
                || FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
                throw new RuntimeException('Select an enabled existing FreePBX trunk as the failover destination.');
            }
            if ((int) $destinationId === (int) ($control['primary_trunk_id'] ?? 0)) {
                throw new RuntimeException('The failover trunk must differ from the supervised primary trunk.');
            }
            return;
        }
        if ($type !== 'misc_destination') {
            throw new RuntimeException('Select a supported failover destination type.');
        }

        $destination = $this->getMiscDestination((int) $destinationId);
        if (!is_array($destination) || (int) ($destination['id'] ?? 0) !== (int) $destinationId
            || trim((string) ($destination['destdial'] ?? '')) === '') {
            throw new RuntimeException('Select an existing, configured Miscellaneous Destination.');
        }
        $number = FailoverPolicy::normaliseUkTelephoneNumber((string) $destination['destdial']);
        if ($number !== null && $this->isLocalInboundNumber($number)) {
            throw new RuntimeException('The Miscellaneous Destination dials a local inbound DID and could create a routing loop.');
        }
    }

    private function selectedFailoverDestinationId(array $control, string $type): string
    {
        if ($type === 'trunk') {
            return trim((string) ($control['failover_trunk_id'] ?? $control['failover_destination_id'] ?? ''));
        }
        if ($type === 'misc_destination') {
            return trim((string) ($control['failover_misc_destination_id'] ?? $control['failover_destination_id'] ?? ''));
        }
        return '';
    }

    private function failoverDestinationMetadata(array $control): ?array
    {
        $type = (string) ($control['failover_type'] ?? 'number');
        $destinationId = $this->selectedFailoverDestinationId($control, $type);
        if (!ctype_digit($destinationId) || (int) $destinationId < 1) {
            return null;
        }
        if ($type === 'trunk') {
            $trunk = $this->FreePBX->Core()->getTrunkByID((int) $destinationId);
            if (!is_array($trunk)) {
                return null;
            }
            return [
                'trunkid' => $trunk['trunkid'] ?? $destinationId,
                'tech' => $trunk['tech'] ?? '',
                'channelid' => $trunk['channelid'] ?? '',
                'disabled' => $trunk['disabled'] ?? '',
                'dialoutprefix' => $trunk['dialoutprefix'] ?? '',
                'outcid' => $trunk['outcid'] ?? '',
                'keepcid' => $trunk['keepcid'] ?? '',
                'maxchans' => $trunk['maxchans'] ?? '',
                'dialopts' => $trunk['dialopts'] ?? '',
            ];
        }
        if ($type === 'misc_destination') {
            $destination = $this->getMiscDestination((int) $destinationId);
            if (!is_array($destination)) {
                return null;
            }
            return [
                'id' => $destination['id'] ?? $destinationId,
                'description' => $destination['description'] ?? '',
                'destdial' => $destination['destdial'] ?? '',
            ];
        }
        return null;
    }

    private function activeFailoverDestinationMatches(string $output, array $control): bool
    {
        $type = (string) ($control['failover_type'] ?? 'number');
        if ($type === 'number') {
            return strpos($output, 'Local/' . $control['failover_number'] . '@outrt-' . (int) $control['outbound_route_id'] . '/n') !== false;
        }
        $destinationId = $this->selectedFailoverDestinationId($control, $type);
        if ($destinationId === '' || !ctype_digit($destinationId) || (int) $destinationId < 1) {
            return false;
        }
        $context = $type === 'trunk' ? 'ext-trunk' : ($type === 'misc_destination' ? 'ext-miscdests' : '');
        return $context !== '' && strpos($output, 'Goto(' . $context . ',' . (int) $destinationId . ',1)') !== false;
    }

    private function destinationReferences(array $control): array
    {
        $destination = 'app-failovercontrol,' . $control['id'] . ',1';
        $references = $this->FreePBX->Destinations()->getAllInUseDestinations([$destination]);
        $descriptions = [];
        foreach ($references as $items) {
            foreach ($items as $item) {
                if (!empty($item['description'])) {
                    $descriptions[] = (string) $item['description'];
                }
            }
        }
        return array_values(array_unique($descriptions));
    }

    private function getMiscDestination(int $id): ?array
    {
        $nativeDestinations = $this->FreePBX->Destinations()->getModuleDestinations('miscdests');
        $nativeDestination = 'ext-miscdests,' . $id . ',1';
        $registered = false;
        foreach (is_array($nativeDestinations) ? $nativeDestinations : [] as $candidate) {
            if ((string) ($candidate['destination'] ?? '') === $nativeDestination) {
                $registered = true;
                break;
            }
        }
        if (!$registered) {
            return null;
        }

        $destination = $this->FreePBX->Miscdests()->get($id);
        if (!is_array($destination)) {
            return null;
        }
        if (isset($destination['id'])) {
            return $destination;
        }
        return isset($destination[0]) && is_array($destination[0]) ? $destination[0] : null;
    }

    private function outboundRoutes(): array
    {
        $routes = $this->FreePBX->Core()->getAllRoutes();
        return is_array($routes) ? $routes : [];
    }

    private function outboundRouteReady(int $routeId, string $number): bool
    {
        $selectedRoute = null;
        foreach ($this->outboundRoutes() as $route) {
            if ((int) ($route['route_id'] ?? 0) === $routeId) {
                $selectedRoute = $route;
                break;
            }
        }
        if (!is_array($selectedRoute)
            || trim((string) ($selectedRoute['password'] ?? '')) !== ''
            || !empty($selectedRoute['time_group_id'])
            || !empty($selectedRoute['emergency_route'])
            || trim((string) ($selectedRoute['dest'] ?? '')) !== '') {
            return false;
        }
        if (!FailoverPolicy::routePreservesUkDestination($routeId, $number, $this->FreePBX->Core())) {
            return false;
        }

        $numberMatchesRoute = false;
        foreach ($this->FreePBX->Core()->getRoutePatternsByID($routeId) as $pattern) {
            if (!empty($pattern['match_cid'])) {
                continue;
            }
            $dialPattern = (string) ($pattern['match_pattern_prefix'] ?? '') . (string) ($pattern['match_pattern_pass'] ?? '');
            if (FailoverPolicy::matchesAsteriskPattern($number, $dialPattern)) {
                $numberMatchesRoute = true;
                break;
            }
        }
        if (!$numberMatchesRoute) {
            return false;
        }

        $trunkIds = $this->FreePBX->Core()->getRouteTrunksByID($routeId);
        if (!is_array($trunkIds) || !$trunkIds) {
            return false;
        }
        foreach ($trunkIds as $trunkId) {
            $trunk = $this->FreePBX->Core()->getTrunkByID((int) $trunkId);
            if (is_array($trunk) && !FailoverPolicy::isTrunkDisabled($trunk['disabled'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function matchingActiveRoutePattern(int $routeId, string $number): ?string
    {
        foreach ($this->FreePBX->Core()->getRoutePatternsByID($routeId) as $pattern) {
            if (!empty($pattern['match_cid'])) {
                continue;
            }
            $formatted = function_exists('core_routing_formatpattern')
                ? core_routing_formatpattern($pattern)
                : null;
            $dialPattern = is_array($formatted)
                ? (string) ($formatted['dial_pattern'] ?? '')
                : (string) ($pattern['match_pattern_prefix'] ?? '') . (string) ($pattern['match_pattern_pass'] ?? '');
            if ($dialPattern !== '' && FailoverPolicy::matchesAsteriskPattern($number, $dialPattern)) {
                return $dialPattern;
            }
        }
        return null;
    }

    private function activeFailurePolicyMatches(string $output, array $control): bool
    {
        // Check ordered instructions and priority labels, not just tokens that may be unreachable.
        $normalise = static fn (string $instruction): string => preg_replace_callback(
            '/^[A-Za-z][A-Za-z0-9_]*(?=\()/',
            static fn (array $match): string => strtolower($match[0]),
            $instruction
        );
        $actual = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(?:\'[^\']+\'\s*=>|\[([a-zA-Z0-9_-]+)\])?\s*(\d+)\.\s+([A-Za-z][A-Za-z0-9_]*\(.*\))(?:\s+\[[^\r\n]*\])?\s*$/', $line, $matches) === 1) {
                if ((int) $matches[2] !== count($actual) + 1) {
                    return false;
                }
                $actual[] = [$matches[1], $normalise($matches[3])];
            } elseif (preg_match('/(?:^|\s)\d+\.\s/', $line) === 1) {
                return false;
            }
        }
        $expected = [];
        $add = static function (string $instruction, string $label = '') use (&$expected, $normalise): void {
            $expected[] = [$label, $normalise($instruction)];
        };
        $add('GotoIf($["${FC_ACTIVE}" = "1"]?loop_blocked)');
        $add('Set(__FC_ACTIVE=1)');
        $add('Set(FC_DID=${FILTER(0-9+,${FROM_DID})})');
        $add('GotoIf($[${REGEX("^[+]?[0-9]+$" ${FROM_DID})} != 1]?invalid_did)');
        $add('GotoIf($["${FC_DID}" != "${FROM_DID}"]?invalid_did)');
        if ((int) $control['ring_timeout'] > 0) {
            $add('Set(TRUNK_RING_TIMER=' . (int) $control['ring_timeout'] . ')');
        }
        $add('Set(DIALSTATUS=)');
        $add('HangupCauseClear()');
        $add('Gosub(macro-dialout-trunk,s,1(' . (int) $control['primary_trunk_id'] . ',${FC_DID},,on))');
        $add('GotoIf($["${DIALSTATUS}" = "ANSWER"]?done)');
        $conditions = FailoverPolicy::selectedFailureConditions($control);
        $unavailable = in_array('unavailable', $conditions, true);
        $noAnswer = in_array('no_answer', $conditions, true);
        if ((int) $control['enabled'] === 1) {
            if ($unavailable) {
                foreach (['CHANUNAVAIL', 'CONGESTION', 'NOANSWER'] as $status) {
                    $add('GotoIf($["${DIALSTATUS}" = "' . $status . '"]?test_unavailable)');
                }
            }
            if (in_array('busy', $conditions, true)) {
                $add('GotoIf($["${DIALSTATUS}" = "BUSY"]?failover)');
            }
            if ($noAnswer && !$unavailable) {
                $add('GotoIf($["${DIALSTATUS}" = "NOANSWER"]?failover)');
            }
            $add('Goto(primary_result)');
            if ($unavailable) {
                $add('Set(FC_PEERS=${HANGUPCAUSE_KEYS()})', 'test_unavailable');
                $add('Set(FC_PEER=${CUT(FC_PEERS,\\,,1)})');
                $add('GotoIf($["${FC_PEER}" = ""]?cause_only)');
                $add('Set(FC_TECH_CAUSE=${HANGUPCAUSE(${FC_PEER},tech)})');
                $add('GotoIf(${REGEX("SIP 408" ${FC_TECH_CAUSE})}?test_timeout)');
                $add('GotoIf(${REGEX("SIP (503|504)" ${FC_TECH_CAUSE})}?failover)');
                $add('Set(FC_AST_CAUSE=${HANGUPCAUSE})', 'cause_only');
                $add('GotoIf($["${DIALSTATUS}" = "NOANSWER" & "${FC_AST_CAUSE}" = "18" & "${RINGTIME}" = ""]?failover)');
                $add('GotoIf($["${DIALSTATUS}" = "CHANUNAVAIL" & "${FC_AST_CAUSE}" = "20"]?failover)');
                $add('GotoIf($["${DIALSTATUS}" = "CHANUNAVAIL" & ("${FC_AST_CAUSE}" = "38" | "${FC_AST_CAUSE}" = "41")]?failover)');
                $add('GotoIf($["${DIALSTATUS}" = "CONGESTION" & ("${FC_AST_CAUSE}" = "38" | "${FC_AST_CAUSE}" = "41" | "${FC_AST_CAUSE}" = "42" | "${FC_AST_CAUSE}" = "102")]?failover)');
                if ($noAnswer) {
                    $add('GotoIf($["${DIALSTATUS}" = "NOANSWER"]?failover)');
                }
                $add('Goto(primary_result)');
                $add('GotoIf($["${RINGTIME}" = ""]?failover:test_timeout_after_ringing)', 'test_timeout');
                $add('GotoIf($["${DIALSTATUS}" = "NOANSWER"]?' . ($noAnswer ? 'failover' : 'primary_result') . ':primary_result)', 'test_timeout_after_ringing');
            }
            $add('NoOp(FAILOVERCONTROL:' . $control['id'] . ':failover-attempt:DID:${FC_DID}:STATUS:${DIALSTATUS}:CAUSE:${FC_TECH_CAUSE}:UID:${UNIQUEID})', 'failover');
            if (($control['failover_type'] ?? 'number') === 'number') {
                $add('Dial(Local/' . $control['failover_number'] . '@outrt-' . (int) $control['outbound_route_id'] . '/n,,g)');
                $add('GotoIf($["${DIALSTATUS}" = "ANSWER"]?done)');
                $add('NoOp(FAILOVERCONTROL:' . $control['id'] . ':fallback-result:DID:${FC_DID}:STATUS:${DIALSTATUS}:UID:${UNIQUEID})');
                $add('Goto(generic_failure)');
            } else {
                $context = $control['failover_type'] === 'trunk' ? 'ext-trunk' : 'ext-miscdests';
                $add('Goto(' . $context . ',' . (int) $control['failover_destination_id'] . ',1)');
            }
        } else {
            $add('Goto(primary_result)');
        }
        $add('Hangup(21)', 'invalid_did');
        $add('Hangup()', 'done');
        $add('NoOp(FAILOVERCONTROL:' . $control['id'] . ':recursive-call-blocked:UID:${UNIQUEID})', 'loop_blocked');
        $add('Hangup(21)');
        $add('GotoIf($["${DIALSTATUS}" = "BUSY"]?macro-dialout-trunk,s-BUSY,1:check_noanswer)', 'primary_result');
        $add('GotoIf($["${DIALSTATUS}" = "NOANSWER"]?macro-dialout-trunk,s-NOANSWER,1:generic_failure)', 'check_noanswer');
        $add('Progress()', 'generic_failure');
        $add('Gosub(macro-outisbusy,s,1())');
        $add('Congestion(20)');
        $add('Hangup()');
        return $actual === $expected;
    }

    private function isLocalInboundNumber(string $number): bool
    {
        foreach ($this->FreePBX->Core()->getAllDIDs() as $did) {
            $pattern = (string) ($did['extension'] ?? '');
            $normalisedDid = FailoverPolicy::normaliseUkTelephoneNumber($pattern);
            if ($normalisedDid === $number || FailoverPolicy::matchesAsteriskPattern($number, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function repository(): Repository
    {
        return new Repository($this->db());
    }

    private function db(): PDO
    {
        return \FreePBX::Database();
    }

    private function createCsrfToken(): string
    {
        if (!$this->ensureSession()) {
            return '';
        }
        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::CSRF_SESSION_KEY];
    }

    private function validateCsrfToken(): bool
    {
        $sessionToken = (string) ($_SESSION[self::CSRF_SESSION_KEY] ?? '');
        $provided = (string) ($_POST['token'] ?? '');
        return $sessionToken !== '' && $provided !== '' && hash_equals($sessionToken, $provided);
    }

    private function ensureSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        return session_status() !== PHP_SESSION_DISABLED && !headers_sent() && @session_start();
    }

    private function setNotice(string $message, string $type): void
    {
        if ($this->ensureSession()) {
            $_SESSION['failovercontrol_notice'] = ['message' => $message, 'type' => $type];
        }
    }

    private function consumeNotice(): ?array
    {
        if (!$this->ensureSession()) {
            return null;
        }
        $notice = $_SESSION['failovercontrol_notice'] ?? null;
        unset($_SESSION['failovercontrol_notice']);
        return is_array($notice) ? $notice : null;
    }
}