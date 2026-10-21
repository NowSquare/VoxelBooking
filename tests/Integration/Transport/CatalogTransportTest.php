<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\ActorContext;
use App\Engine\{Database, Ulid};
use Tests\Support\Transport\TransportTestCase;

/**
 * Catalog and schedule writes over HTTP v2 and native MCP.
 *
 * One test per operation covers both transports. Each transport acts on its own
 * record (or on the state the previous transport left), and every effect is read
 * back through the matching read operation or the database.
 */
final class CatalogTransportTest extends TransportTestCase
{
    /** kind => [entity, list table, booking pattern, create fields, update changes] */
    private const KINDS = [
        'services' => ['service', 'services', 'timeslot', ['name' => 'Transport service', 'duration_minutes' => 30, 'price' => '12.50'], ['name' => 'Renamed service']],
        'staff' => ['staff', 'staff', 'timeslot', ['name' => 'Transport staff'], ['title' => 'Instructor']],
        'resources' => ['resource', 'resources', 'resource', ['name' => 'Transport room', 'price_per_night' => '50.00', 'amenities' => ['Desk']], ['capacity' => 4]],
        'capacity_slots' => ['capacity_slot', 'capacity_slots', 'capacity', ['day_of_week' => 1, 'start_time' => '18:00', 'end_time' => '20:00', 'max_capacity' => 12, 'max_party_size' => 6], []],
        'events' => ['event', 'events', 'event', ['name' => 'Transport workshop', 'start_datetime' => '2027-06-01T18:00:00+00:00', 'end_datetime' => '2027-06-01T20:00:00+00:00', 'max_participants' => 12], ['name' => 'Renamed workshop']],
        'blocked_dates' => ['blocked_date', 'blocked_dates', 'timeslot', ['start_date' => '2027-06-10', 'end_date' => '2027-06-11', 'reason' => 'Maintenance'], []],
    ];

    private array $businesses = [];
    private ActorContext $session;
    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['timeslot', 'resource', 'capacity', 'event'] as $pattern) {
            $this->businesses[$pattern] = $this->tenant($pattern)['id'];
        }
        $this->session = $this->operatorSession(array_values($this->businesses));
        $operations = ['schedule_get', 'schedule_replace', 'schedule_reset_staff'];
        foreach (self::KINDS as [$entity]) {
            foreach (['create', 'update', 'set_active', 'reorder', 'delete', 'get'] as $action) {
                if ($this->registry->get($entity . '_' . $action) !== null) {
                    $operations[] = $entity . '_' . $action;
                }
            }
        }
        $this->client = $this->connect($this->session, $operations);
    }

    private function fields(string $kind, string $suffix): array
    {
        $fields = self::KINDS[$kind][3];
        if (isset($fields['name'])) {
            $fields['name'] .= ' ' . $suffix;
        }
        if ($kind === 'staff') {
            $fields['email'] = 'staff-' . strtolower(Ulid::generate()) . '@example.test';
        }
        // Records of one kind may not overlap, so every record gets its own day.
        $offset = abs(crc32($suffix)) % 300;
        if ($kind === 'blocked_dates') {
            $start = (new \DateTimeImmutable('2027-01-01'))->modify('+' . $offset . ' days');
            $fields = ['start_date' => $start->format('Y-m-d'), 'end_date' => $start->modify('+1 day')->format('Y-m-d')] + $fields;
        }
        if ($kind === 'capacity_slots') {
            $fields['day_of_week'] = $offset % 7;
            $fields['start_time'] = sprintf('%02d:00', 6 + intdiv($offset, 7) % 16);
            $fields['end_time'] = sprintf('%02d:30', 6 + intdiv($offset, 7) % 16);
        }
        return $fields;
    }

    /** Setup only: an in-process create by the signed-in operator. */
    private function seed(string $kind, string $suffix): array
    {
        [$entity, , $pattern] = self::KINDS[$kind];
        return $this->seedCommand($this->session, $entity . '_create', ['business_id' => $this->businesses[$pattern], ...$this->fields($kind, $suffix)])['data'];
    }

    private function stored(string $kind, string $id): array
    {
        [$entity, , $pattern] = self::KINDS[$kind];
        return $this->read($this->session, $entity . '_get', ['business_id' => $this->businesses[$pattern], 'id' => $id]);
    }

    private function assertCreate(string $kind): void
    {
        [$entity, $table, $pattern] = self::KINDS[$kind];
        foreach (self::TRANSPORTS as $transport) {
            $fields = $this->fields($kind, $transport);
            $data = $this->call($transport, $this->client, $entity . '_create', ['business_id' => $this->businesses[$pattern], ...$fields])['data'];
            self::assertSame($this->businesses[$pattern], $data['business_id']);
            foreach (array_intersect_key($fields, array_flip(['name', 'start_date', 'max_capacity'])) as $field => $value) {
                self::assertSame($value, $data[$field], $kind . '.' . $field . ' over ' . $transport);
            }
            self::assertSame($data, $this->stored($kind, $data['id']), $kind . ' persisted over ' . $transport);
            self::assertSame(1, (int) Database::query("SELECT COUNT(*) AS n FROM `$table` WHERE id=? AND tenant_id=?", [$data['id'], $this->businesses[$pattern]])[0]['n']);
        }
    }

    private function assertUpdate(string $kind): void
    {
        [$entity, , $pattern, , $changes] = self::KINDS[$kind];
        foreach (self::TRANSPORTS as $transport) {
            $record = $this->seed($kind, $transport);
            $change = $changes;
            if (isset($change['name'])) {
                $change['name'] .= ' over ' . $transport;
            }
            $data = $this->call($transport, $this->client, $entity . '_update', ['business_id' => $this->businesses[$pattern], 'id' => $record['id'], 'expected_version' => $record['version'], ...$change])['data'];
            foreach ($change as $field => $value) {
                self::assertSame($value, $data[$field], $kind . '.' . $field . ' over ' . $transport);
            }
            self::assertNotSame($record['version'], $data['version']);
            self::assertSame($data, $this->stored($kind, $record['id']));
        }
    }

    private function assertSetActive(string $kind): void
    {
        [$entity, , $pattern] = self::KINDS[$kind];
        $record = $this->seed($kind, 'toggle');
        // HTTP deactivates, then MCP reactivates the same record.
        foreach (['http' => false, 'mcp' => true] as $transport => $active) {
            $data = $this->call($transport, $this->client, $entity . '_set_active', ['business_id' => $this->businesses[$pattern], 'id' => $record['id'], 'expected_version' => $record['version'], 'is_active' => $active])['data'];
            self::assertSame($active, $data['is_active'], $transport);
            $record = $this->stored($kind, $record['id']);
            self::assertSame($active, $record['is_active'], $transport);
        }
    }

    private function assertReorder(string $kind): void
    {
        [$entity, , $pattern] = self::KINDS[$kind];
        $first = $this->seed($kind, 'first');
        $this->seed($kind, 'second');
        // HTTP moves the first record down, then MCP moves it back up.
        foreach (['http' => 'down', 'mcp' => 'up'] as $transport => $direction) {
            $before = $this->stored($kind, $first['id']);
            $data = $this->call($transport, $this->client, $entity . '_reorder', ['business_id' => $this->businesses[$pattern], 'id' => $first['id'], 'expected_version' => $before['version'], 'direction' => $direction])['data'];
            $direction === 'down'
                ? self::assertGreaterThan($before['sort_order'], $data['sort_order'], $transport)
                : self::assertLessThan($before['sort_order'], $data['sort_order'], $transport);
            self::assertSame($data['sort_order'], $this->stored($kind, $first['id'])['sort_order']);
        }
    }

    private function assertDelete(string $kind): void
    {
        [$entity, $table, $pattern] = self::KINDS[$kind];
        foreach (self::TRANSPORTS as $transport) {
            $record = $this->seed($kind, $transport);
            $data = $this->call($transport, $this->client, $entity . '_delete', ['business_id' => $this->businesses[$pattern], 'id' => $record['id'], 'expected_version' => $record['version']])['data'];
            self::assertTrue($data['deleted'], $transport);
            self::assertSame($record['id'], $data['id']);
            self::assertSame(0, (int) Database::query("SELECT COUNT(*) AS n FROM `$table` WHERE id=?", [$record['id']])[0]['n'], $transport);
        }
    }

    public function testServiceCreateOverHttpAndMcp(): void { $this->assertCreate('services'); }
    public function testServiceUpdateOverHttpAndMcp(): void { $this->assertUpdate('services'); }
    public function testServiceSetActiveOverHttpAndMcp(): void { $this->assertSetActive('services'); }
    public function testServiceReorderOverHttpAndMcp(): void { $this->assertReorder('services'); }

    public function testStaffCreateOverHttpAndMcp(): void { $this->assertCreate('staff'); }
    public function testStaffUpdateOverHttpAndMcp(): void { $this->assertUpdate('staff'); }
    public function testStaffSetActiveOverHttpAndMcp(): void { $this->assertSetActive('staff'); }
    public function testStaffReorderOverHttpAndMcp(): void { $this->assertReorder('staff'); }

    public function testResourceCreateOverHttpAndMcp(): void { $this->assertCreate('resources'); }
    public function testResourceUpdateOverHttpAndMcp(): void { $this->assertUpdate('resources'); }
    public function testResourceSetActiveOverHttpAndMcp(): void { $this->assertSetActive('resources'); }
    public function testResourceReorderOverHttpAndMcp(): void { $this->assertReorder('resources'); }

    public function testCapacitySlotCreateOverHttpAndMcp(): void { $this->assertCreate('capacity_slots'); }
    public function testCapacitySlotSetActiveOverHttpAndMcp(): void { $this->assertSetActive('capacity_slots'); }
    public function testCapacitySlotDeleteOverHttpAndMcp(): void { $this->assertDelete('capacity_slots'); }

    public function testEventCreateOverHttpAndMcp(): void { $this->assertCreate('events'); }
    public function testEventUpdateOverHttpAndMcp(): void { $this->assertUpdate('events'); }
    public function testEventSetActiveOverHttpAndMcp(): void { $this->assertSetActive('events'); }
    public function testEventDeleteOverHttpAndMcp(): void { $this->assertDelete('events'); }

    public function testBlockedDateCreateOverHttpAndMcp(): void { $this->assertCreate('blocked_dates'); }
    public function testBlockedDateDeleteOverHttpAndMcp(): void { $this->assertDelete('blocked_dates'); }

    public function testScheduleReplaceOverHttpAndMcp(): void
    {
        $input = ['business_id' => $this->businesses['timeslot']];
        $windows = [
            'http' => [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
            'mcp' => [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'], ['day_of_week' => 4, 'start_time' => '10:00', 'end_time' => '14:00']],
        ];
        foreach (self::TRANSPORTS as $transport) {
            $before = $this->read($this->session, 'schedule_get', $input);
            $data = $this->call($transport, $this->client, 'schedule_replace', [...$input, 'expected_version' => $before['version'], 'windows' => $windows[$transport]])['data'];
            self::assertCount(count($windows[$transport]), $data['windows'], $transport);
            self::assertSame($windows[$transport][0]['day_of_week'], $data['windows'][0]['day_of_week']);
            self::assertSame($data, $this->read($this->session, 'schedule_get', $input), $transport);
        }
    }

    public function testScheduleResetStaffOverHttpAndMcp(): void
    {
        $input = ['business_id' => $this->businesses['timeslot']];
        $business = $this->read($this->session, 'schedule_get', $input);
        $business = $this->executor->execute($this->session, 'schedule_replace', [...$input, 'expected_version' => $business['version'],
            'windows' => [['day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '16:00']]], Ulid::generate())['data'];
        foreach (self::TRANSPORTS as $transport) {
            $staff = $this->seed('staff', 'schedule ' . $transport);
            $staffInput = [...$input, 'staff_id' => $staff['id']];
            $current = $this->read($this->session, 'schedule_get', $staffInput);
            $custom = $this->executor->execute($this->session, 'schedule_replace', [...$staffInput, 'expected_version' => $current['version'],
                'windows' => [['day_of_week' => 3, 'start_time' => '11:00', 'end_time' => '13:00']]], Ulid::generate())['data'];
            self::assertFalse($custom['inherits_business_schedule']);
            $data = $this->call($transport, $this->client, 'schedule_reset_staff', [...$staffInput, 'expected_version' => $custom['version']])['data'];
            self::assertTrue($data['inherits_business_schedule'], $transport);
            self::assertSame($staff['id'], $data['staff_id']);
            self::assertSame($business['windows'], $data['windows']);
            self::assertSame($data, $this->read($this->session, 'schedule_get', $staffInput), $transport);
        }
    }
}
