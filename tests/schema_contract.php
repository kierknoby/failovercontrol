<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Schema.php';

use FreePBX\modules\Failovercontrol\Schema;

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': got ' . var_export($actual, true));
    }
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec("CREATE TABLE failovercontrol_controls (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    primary_trunk_id INTEGER NULL,
    failover_number VARCHAR(16) NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 0,
    on_unavailable INTEGER NOT NULL DEFAULT 1,
    on_busy INTEGER NOT NULL DEFAULT 0,
    on_no_answer INTEGER NOT NULL DEFAULT 0,
    ring_timeout INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
)");
$database->exec("INSERT INTO failovercontrol_controls VALUES
    ('11111111-1111-4111-8111-111111111111', 'Legacy Control', '', 3, '07876838629', 0, 1, 0, 0, 0, '2026-10-27 00:00:00')");

Schema::install($database);
Schema::install($database);
$columns = $database->query('PRAGMA table_info(failovercontrol_controls)')->fetchAll(PDO::FETCH_COLUMN, 1);
$assertSame(true, in_array('outbound_route_id', $columns, true), 'Legacy migration did not add the outbound route column');
$assertSame(true, in_array('failover_type', $columns, true), 'Legacy migration did not add the destination type column');
$assertSame(true, in_array('failover_destination_id', $columns, true), 'Legacy migration did not add the native destination identifier');
$assertSame('Legacy Control', $database->query('SELECT name FROM failovercontrol_controls')->fetchColumn(), 'Upgrade did not preserve existing customer data');
$legacyDestination = $database->query('SELECT failover_type, failover_destination_id, failover_number FROM failovercontrol_controls')->fetch(PDO::FETCH_ASSOC);
$assertSame('number', $legacyDestination['failover_type'], 'Migration did not preserve the legacy number destination type');
$assertSame('07876838629', $legacyDestination['failover_number'], 'Migration discarded the existing failover number');
$indexes = $database->query('PRAGMA index_list(failovercontrol_controls)')->fetchAll(PDO::FETCH_ASSOC);
$assertSame(true, in_array('failovercontrol_outbound_route', array_column($indexes, 'name'), true), 'Upgrade did not create the outbound route index');

$database->exec('DROP INDEX failovercontrol_outbound_route');
Schema::install($database);
$indexes = $database->query('PRAGMA index_list(failovercontrol_controls)')->fetchAll(PDO::FETCH_ASSOC);
$assertSame(true, in_array('failovercontrol_outbound_route', array_column($indexes, 'name'), true), 'Retry did not repair a missing route index');
$assertSame(true, Schema::isReady($database), 'Schema readiness check failed after installation');

echo "Failover schema contracts passed\n";
