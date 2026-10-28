<?php
declare(strict_types=1);

namespace FreePBX\modules\Failovercontrol;

use FailoverControl\FailoverPolicy;
use PDO;
use InvalidArgumentException;

final class Repository
{
    public function __construct(private PDO $database)
    {
    }

    public function all(): array
    {
        $statement = $this->database->query('SELECT * FROM failovercontrol_controls ORDER BY name, id');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(string $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM failovercontrol_controls WHERE id = :id');
        $statement->execute([':id' => $id]);
        $control = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($control) ? $control : null;
    }

    public function save(array $input, bool $isNew = false): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $trunkId = trim((string) ($input['primary_trunk_id'] ?? ''));
        $routeId = trim((string) ($input['outbound_route_id'] ?? ''));
        $failoverType = trim((string) ($input['failover_type'] ?? 'number'));
        $failoverDestinationId = trim((string) ($input['failover_destination_id'] ?? ''));
        $number = trim((string) ($input['failover_number'] ?? ''));
        $ringTimeout = trim((string) ($input['ring_timeout'] ?? '0'));

        if (!in_array($failoverType, ['number', 'trunk', 'misc_destination'], true)) {
            throw new InvalidArgumentException('Select a supported failover destination type.');
        }
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Enter a control name of no more than 80 characters.');
        }
        if (mb_strlen($description) > 500) {
            throw new InvalidArgumentException('The description must be no more than 500 characters.');
        }
        if ($trunkId !== '' && (!ctype_digit($trunkId) || (int) $trunkId < 1)) {
            throw new InvalidArgumentException('Select a valid PJSIP trunk.');
        }
        if ($routeId !== '' && (!ctype_digit($routeId) || (int) $routeId < 1)) {
            throw new InvalidArgumentException('Select a valid outbound route.');
        }
        if ($failoverType === 'trunk') {
            $failoverDestinationId = trim((string) ($input['failover_trunk_id'] ?? $failoverDestinationId));
        } elseif ($failoverType === 'misc_destination') {
            $failoverDestinationId = trim((string) ($input['failover_misc_destination_id'] ?? $failoverDestinationId));
        } else {
            $failoverDestinationId = '';
        }
        if ($failoverType !== 'number' && $failoverDestinationId !== ''
            && (!ctype_digit($failoverDestinationId) || (int) $failoverDestinationId < 1)) {
            throw new InvalidArgumentException('Select a valid existing failover destination.');
        }
        if ($failoverType !== 'number') {
            $number = '';
        } elseif ($number !== '') {
            $normalised = \FailoverControl\FailoverPolicy::normaliseUkTelephoneNumber($number);
            if ($normalised === null) {
                throw new InvalidArgumentException('Enter a UK geographic or mobile number. 070, 076 and premium-rate ranges are not allowed.');
            }
            $number = $normalised;
        }
        if ($ringTimeout === '' || !ctype_digit($ringTimeout) || (int) $ringTimeout > 180) {
            throw new InvalidArgumentException('The primary ring limit must be between 0 and 180 seconds.');
        }
        $validatedSettings = [
            'enabled' => self::checkbox($input, 'enabled'),
            'on_unavailable' => self::checkbox($input, 'on_unavailable'),
            'on_busy' => self::checkbox($input, 'on_busy'),
            'on_no_answer' => self::checkbox($input, 'on_no_answer'),
        ];
        if (!empty($validatedSettings['enabled']) && !FailoverPolicy::hasFailureCondition($validatedSettings)) {
            throw new InvalidArgumentException('Enable at least one failure condition before enabling failover.');
        }

        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '') {
            $id = self::newId();
            $isNew = true;
        } elseif (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id)) {
            throw new InvalidArgumentException('The selected control no longer exists.');
        }

        $values = [
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':primary_trunk_id' => $trunkId === '' ? null : (int) $trunkId,
            ':outbound_route_id' => $routeId === '' ? null : (int) $routeId,
            ':failover_type' => $failoverType,
            ':failover_destination_id' => $failoverDestinationId,
            ':failover_number' => $number,
            ':enabled' => $validatedSettings['enabled'],
            ':on_unavailable' => $validatedSettings['on_unavailable'],
            ':on_busy' => $validatedSettings['on_busy'],
            ':on_no_answer' => $validatedSettings['on_no_answer'],
            ':ring_timeout' => (int) $ringTimeout,
            ':updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = $this->find($id);
        if ($isNew && $existing !== null) {
            $sameSubmission = (string) $existing['name'] === $name
                && (string) $existing['description'] === $description
                && (string) $existing['primary_trunk_id'] === (string) ($values[':primary_trunk_id'] ?? '')
                && (string) $existing['outbound_route_id'] === (string) ($values[':outbound_route_id'] ?? '')
                && (string) ($existing['failover_type'] ?? 'number') === $failoverType
                && (string) ($existing['failover_destination_id'] ?? '') === $failoverDestinationId
                && (string) $existing['failover_number'] === $number
                && (int) $existing['enabled'] === $validatedSettings['enabled']
                && (int) $existing['on_unavailable'] === $validatedSettings['on_unavailable']
                && (int) $existing['on_busy'] === $validatedSettings['on_busy']
                && (int) $existing['on_no_answer'] === $validatedSettings['on_no_answer']
                && (int) $existing['ring_timeout'] === (int) $ringTimeout;
            if ($sameSubmission) {
                return $id;
            }
            throw new InvalidArgumentException('This new control identifier is already in use. Reload the form and try again.');
        }
        if ($isNew) {
            $statement = $this->database->prepare('INSERT INTO failovercontrol_controls
                (id, name, description, primary_trunk_id, outbound_route_id, failover_type, failover_destination_id, failover_number, enabled, on_unavailable, on_busy, on_no_answer, ring_timeout, updated_at)
                VALUES (:id, :name, :description, :primary_trunk_id, :outbound_route_id, :failover_type, :failover_destination_id, :failover_number, :enabled, :on_unavailable, :on_busy, :on_no_answer, :ring_timeout, :updated_at)');
        } else {
            if ($existing === null) {
                throw new InvalidArgumentException('The selected control no longer exists.');
            }
            $statement = $this->database->prepare('UPDATE failovercontrol_controls SET
                name = :name, description = :description, primary_trunk_id = :primary_trunk_id,
                outbound_route_id = :outbound_route_id, failover_type = :failover_type,
                failover_destination_id = :failover_destination_id, failover_number = :failover_number,
                enabled = :enabled, on_unavailable = :on_unavailable, on_busy = :on_busy,
                on_no_answer = :on_no_answer, ring_timeout = :ring_timeout, updated_at = :updated_at
                WHERE id = :id');
        }
        $statement->execute($values);

        return $id;
    }

    public function delete(string $id): void
    {
        $statement = $this->database->prepare('DELETE FROM failovercontrol_controls WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    private static function checkbox(array $input, string $key): int
    {
        return isset($input[$key]) && (string) $input[$key] === '1' ? 1 : 0;
    }

    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}