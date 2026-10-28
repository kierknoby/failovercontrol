<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../src/FailoverPolicy.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Repository.php';

if (!function_exists('_')) {
    function _(string $message): string { return $message; }
}

$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$control = $payload['control'] ?? null;
if (($argv[1] ?? '') === 'save') {
    $database = new PDO('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \FreePBX\modules\Failovercontrol\Schema::install($database);
    $repository = new \FreePBX\modules\Failovercontrol\Repository($database);
    if ($control !== null) {
        $repository->save($control, true);
    }
    $input = $payload['input'];
    $id = $repository->save($input, ($input['is_new'] ?? '0') === '1');
    echo json_encode($repository->find($id), JSON_THROW_ON_ERROR);
    exit;
}

$_REQUEST = ['view' => 'form'];
$formId = $control['id'] ?? '11111111-1111-4111-8111-111111111111';
$csrfToken = 'view-fixture-token';
$notice = null;
$status = null;
$trunks = [['trunkid' => 3, 'name' => 'Primary PBX', 'channelid' => 'primary-pbx']];
$failoverTrunks = [
    ['trunkid' => 5, 'name' => 'Secondary PBX', 'channelid' => 'secondary-pbx'],
    ['trunkid' => 6, 'name' => 'Alternative PBX', 'channelid' => 'alternative-pbx'],
];
$routes = [['route_id' => 7, 'name' => 'UK failover'], ['route_id' => 8, 'name' => 'Alternative UK route']];
$miscDestinations = [
    ['destination' => 'ext-miscdests,9,1', 'description' => 'Test Misc'],
    ['destination' => 'ext-miscdests,10,1', 'description' => 'Alternative Misc'],
];
require __DIR__ . '/../views/main.php';
