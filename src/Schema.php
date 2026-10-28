<?php
declare(strict_types=1);

namespace FreePBX\modules\Failovercontrol;

use PDO;

final class Schema
{
    public static function install(PDO $database): void
    {
        $driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $database->exec("CREATE TABLE IF NOT EXISTS failovercontrol_controls (
                id CHAR(36) NOT NULL,
                name VARCHAR(80) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                primary_trunk_id INT UNSIGNED NULL,
                outbound_route_id INT UNSIGNED NULL,
                failover_type VARCHAR(24) NOT NULL DEFAULT 'number',
                failover_destination_id VARCHAR(32) NOT NULL DEFAULT '',
                failover_number VARCHAR(16) NOT NULL DEFAULT '',
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                on_unavailable TINYINT(1) NOT NULL DEFAULT 1,
                on_busy TINYINT(1) NOT NULL DEFAULT 0,
                on_no_answer TINYINT(1) NOT NULL DEFAULT 0,
                ring_timeout INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY failovercontrol_name (name),
                KEY failovercontrol_primary_trunk (primary_trunk_id),
                KEY failovercontrol_outbound_route (outbound_route_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $columns = $database->query('SHOW COLUMNS FROM failovercontrol_controls')->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($driver === 'sqlite') {
            $database->exec("CREATE TABLE IF NOT EXISTS failovercontrol_controls (
                id CHAR(36) NOT NULL PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                primary_trunk_id INTEGER NULL,
                outbound_route_id INTEGER NULL,
                failover_type VARCHAR(24) NOT NULL DEFAULT 'number',
                failover_destination_id VARCHAR(32) NOT NULL DEFAULT '',
                failover_number VARCHAR(16) NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 0,
                on_unavailable INTEGER NOT NULL DEFAULT 1,
                on_busy INTEGER NOT NULL DEFAULT 0,
                on_no_answer INTEGER NOT NULL DEFAULT 0,
                ring_timeout INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )");
            $columns = $database->query('PRAGMA table_info(failovercontrol_controls)')->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($columns, 'name');
        } else {
            throw new \RuntimeException('Failover Control supports FreePBX MariaDB/MySQL storage only.');
        }

        if (!in_array('outbound_route_id', $columns, true)) {
            $database->exec($driver === 'mysql'
                ? 'ALTER TABLE failovercontrol_controls ADD outbound_route_id INT UNSIGNED NULL AFTER primary_trunk_id'
                : 'ALTER TABLE failovercontrol_controls ADD COLUMN outbound_route_id INTEGER NULL');
        }
        if (!in_array('failover_type', $columns, true)) {
            $database->exec($driver === 'mysql'
                ? "ALTER TABLE failovercontrol_controls ADD failover_type VARCHAR(24) NOT NULL DEFAULT 'number' AFTER outbound_route_id"
                : "ALTER TABLE failovercontrol_controls ADD COLUMN failover_type VARCHAR(24) NOT NULL DEFAULT 'number'");
        }
        if (!in_array('failover_destination_id', $columns, true)) {
            $database->exec($driver === 'mysql'
                ? "ALTER TABLE failovercontrol_controls ADD failover_destination_id VARCHAR(32) NOT NULL DEFAULT '' AFTER failover_type"
                : "ALTER TABLE failovercontrol_controls ADD COLUMN failover_destination_id VARCHAR(32) NOT NULL DEFAULT ''");
        }

        if ($driver === 'mysql') {
            $indexes = $database->query('SHOW INDEX FROM failovercontrol_controls')->fetchAll(PDO::FETCH_ASSOC);
            $hasRouteIndex = false;
            foreach ($indexes as $index) {
                if (($index['Key_name'] ?? '') === 'failovercontrol_outbound_route') {
                    $hasRouteIndex = true;
                    break;
                }
            }
            if (!$hasRouteIndex) {
                $database->exec('ALTER TABLE failovercontrol_controls ADD KEY failovercontrol_outbound_route (outbound_route_id)');
            }
        } else {
            $database->exec('CREATE INDEX IF NOT EXISTS failovercontrol_outbound_route ON failovercontrol_controls (outbound_route_id)');
        }
    }

    public static function isReady(PDO $database): bool
    {
        try {
            $database->query('SELECT id FROM failovercontrol_controls LIMIT 0');
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}