<?php
declare(strict_types=1);

interface BMO {}

final class FakeDestinationManager
{
    public array $references = ['core' => [['description' => 'Inbound Route: Main Office']]];

    public function getAllInUseDestinations(array $destinations): array
    {
        return $this->references;
    }
}

final class FakeLogger
{
    public function warning(string $message): void {}
}

final class FakeFreePBXForLifecycle
{
    public FakeDestinationManager $destinations;
    public function __construct() { $this->destinations = new FakeDestinationManager(); }
    public function Destinations(): FakeDestinationManager { return $this->destinations; }
    public function Log(): FakeLogger { return new FakeLogger(); }
}

final class FreePBX
{
    public static PDO $database;
    public static function Database(): PDO { return self::$database; }
}

$assertSame = static function ($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
};

require_once __DIR__ . '/../src/FailoverPolicy.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../Failovercontrol.class.php';

FreePBX::$database = new PDO('sqlite::memory:');
FreePBX::$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FreePBX::$database->exec("CREATE TABLE failovercontrol_controls (
    id CHAR(36) PRIMARY KEY, name TEXT, description TEXT, primary_trunk_id INTEGER,
    outbound_route_id INTEGER, failover_type TEXT NOT NULL DEFAULT 'number',
    failover_destination_id TEXT NOT NULL DEFAULT '', failover_number TEXT, enabled INTEGER, on_unavailable INTEGER,
    on_busy INTEGER, on_no_answer INTEGER, ring_timeout INTEGER, updated_at TEXT
)");
FreePBX::$database->exec("INSERT INTO failovercontrol_controls VALUES
    ('11111111-1111-4111-8111-111111111111', 'Main Office', '', 3, 7, 'number', '', '07876838629', 1, 1, 0, 0, 45, '2026-10-27 00:00:00')");

$freepbx = new FakeFreePBXForLifecycle();
$module = new FreePBX\modules\Failovercontrol($freepbx);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['failovercontrol_csrf_token'] = 'expected-token';
$_POST = ['action' => 'delete', 'id' => '11111111-1111-4111-8111-111111111111', 'token' => 'wrong-token'];
$module->doConfigPageInit('failovercontrol');
$assertSame(1, (int) FreePBX::$database->query('SELECT COUNT(*) FROM failovercontrol_controls')->fetchColumn(), 'Invalid CSRF token allowed control deletion');
$deleteMethod = new ReflectionMethod($module, 'deleteControl');
try {
    $deleteMethod->invoke($module, '11111111-1111-4111-8111-111111111111');
    throw new RuntimeException('Referenced control deletion was permitted');
} catch (RuntimeException $exception) {
    $assertSame(true, str_contains($exception->getMessage(), 'Change those destinations'), 'Unexpected referenced-control deletion failure');
}
$assertSame(false, $module->disable(), 'Referenced destination did not block module disable');
$assertSame(false, $module->uninstall(), 'Referenced destination did not block module uninstall');
$assertSame(1, (int) FreePBX::$database->query('SELECT COUNT(*) FROM failovercontrol_controls')->fetchColumn(), 'Refused uninstall removed customer configuration');

$freepbx->destinations->references = [];
$assertSame(true, $module->disable(), 'Unreferenced module could not be disabled');
$assertSame(1, (int) FreePBX::$database->query('SELECT COUNT(*) FROM failovercontrol_controls')->fetchColumn(), 'Disable removed saved configuration');
$assertSame(true, $module->uninstall(), 'Unreferenced module could not be uninstalled');
$assertSame(false, \FreePBX\modules\Failovercontrol\Schema::isReady(FreePBX::$database), 'Uninstall did not remove the owned table');

echo "Failover lifecycle contracts passed\n";
