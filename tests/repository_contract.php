<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/FailoverPolicy.php';
require_once __DIR__ . '/../src/Repository.php';

use FreePBX\modules\Failovercontrol\Repository;

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec("CREATE TABLE failovercontrol_controls (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL,
    primary_trunk_id INTEGER NULL,
    outbound_route_id INTEGER NULL,
    failover_type VARCHAR(24) NOT NULL DEFAULT 'number',
    failover_destination_id VARCHAR(32) NOT NULL DEFAULT '',
    failover_number VARCHAR(16) NOT NULL,
    enabled INTEGER NOT NULL,
    on_unavailable INTEGER NOT NULL,
    on_busy INTEGER NOT NULL,
    on_no_answer INTEGER NOT NULL,
    ring_timeout INTEGER NOT NULL,
    updated_at DATETIME NOT NULL
)");

$repository = new Repository($database);
$id = $repository->save([
    'name' => 'Main Office',
    'description' => 'Primary PBX connection',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '2',
    'failover_number' => '+447876838629',
    'enabled' => '1',
    'on_unavailable' => '1',
    'ring_timeout' => '45',
]);
$assertSame($id, $repository->save([
    'id' => $id,
    'is_new' => '1',
    'name' => 'Main Office',
    'description' => 'Primary PBX connection',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '2',
    'failover_number' => '+447876838629',
    'enabled' => '1',
    'on_unavailable' => '1',
    'ring_timeout' => '45',
], true), 'Retrying the same create request was not idempotent');
$collisionRejected = false;
try {
    $repository->save([
        'id' => $id,
        'name' => 'Different control',
        'primary_trunk_id' => '3',
        'outbound_route_id' => '2',
        'failover_number' => '07876838629',
        'enabled' => '1',
        'on_unavailable' => '1',
        'ring_timeout' => '45',
    ], true);
} catch (InvalidArgumentException $exception) {
    $collisionRejected = true;
}
$assertSame(true, $collisionRejected, 'A conflicting create UUID overwrote an existing control');
$control = $repository->find($id);
$assertSame('Main Office', $control['name'], 'Control name was not persisted');
$assertSame('07876838629', $control['failover_number'], 'Failover number was not normalised before persistence');
$assertSame('number', $control['failover_type'], 'Legacy UK number destination type was not preserved');
$assertSame('1', (string) $control['enabled'], 'Enabled state was not persisted');
$assertSame($id, $repository->save([
    'id' => $id,
    'name' => 'Main Office',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '2',
    'failover_number' => '+447876838629',
    'enabled' => '1',
    'on_unavailable' => '1',
    'ring_timeout' => '45',
]), 'Repeated form submission changed the destination ID');

$repository->save([
    'id' => $id,
    'name' => 'Head Office',
    'description' => '',
    'primary_trunk_id' => '3',
    'outbound_route_id' => '2',
    'failover_number' => '00442079460123',
    'enabled' => '0',
    'on_unavailable' => '1',
    'ring_timeout' => '0',
]);
$updated = $repository->find($id);
$assertSame($id, $updated['id'], 'Editing changed the destination identity');
$assertSame('Head Office', $updated['name'], 'Edited control name was not persisted');
$assertSame('02079460123', $updated['failover_number'], 'Edited failover number was not normalised');
$assertSame('0', (string) $updated['enabled'], 'Disabled state was not persisted');

$trunkFailoverId = $repository->save([
    'name' => 'Trunk Failover',
    'primary_trunk_id' => '3',
    'failover_type' => 'trunk',
    'failover_trunk_id' => '5',
    'enabled' => '0',
    'ring_timeout' => '0',
]);
$trunkFailover = $repository->find($trunkFailoverId);
$assertSame('trunk', $trunkFailover['failover_type'], 'Native trunk destination type was not persisted');
$assertSame('5', (string) $trunkFailover['failover_destination_id'], 'Native trunk destination identifier was not persisted');
$assertSame('', $trunkFailover['failover_number'], 'Stale telephone destination data remained active for a native trunk target');
$miscFailoverId = $repository->save([
    'name' => 'Misc Failover',
    'primary_trunk_id' => '3',
    'failover_type' => 'misc_destination',
    'failover_misc_destination_id' => '9',
    'enabled' => '0',
    'ring_timeout' => '0',
]);
$miscFailover = $repository->find($miscFailoverId);
$assertSame('misc_destination', $miscFailover['failover_type'], 'Miscellaneous Destination type was not persisted');
$assertSame('9', (string) $miscFailover['failover_destination_id'], 'Miscellaneous Destination identifier was not persisted');

try {
    $repository->save([
        'id' => $id,
        'name' => 'Invalid',
        'primary_trunk_id' => '3',
        'failover_number' => '07012345678',
        'ring_timeout' => '0',
    ]);
    throw new RuntimeException('An excluded 070 destination was accepted');
} catch (InvalidArgumentException $exception) {
    $assertSame('Head Office', $repository->find($id)['name'], 'Rejected input changed persisted configuration');
}

try {
    $repository->save([
        'id' => $id,
        'name' => 'No conditions',
        'primary_trunk_id' => '3',
        'failover_number' => '07876838629',
        'enabled' => '1',
        'ring_timeout' => '0',
    ]);
    throw new RuntimeException('An enabled control without failure conditions was accepted');
} catch (InvalidArgumentException $exception) {
    $assertSame('Head Office', $repository->find($id)['name'], 'Rejected condition settings changed persisted configuration');
}

$repository->delete($id);
$assertSame(null, $repository->find($id), 'Control deletion failed');
$repository->delete($trunkFailoverId);
$repository->delete($miscFailoverId);

echo "Failover repository contracts passed\n";
