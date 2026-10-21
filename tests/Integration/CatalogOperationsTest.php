<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Catalog\CatalogService;
use App\Application\Catalog\CatalogOperations;
use App\Agent\SchemaValidator;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

final class CatalogOperationsTest extends TestCase
{
    private array $businesses = [];
    private ActorContext $actor;

    protected function setUp(): void
    {
        TestFixtures::provision();
        foreach (['timeslot', 'resource', 'capacity', 'event'] as $pattern) {
            $id = Ulid::generate();
            Database::execute("INSERT INTO tenants (id, name, slug, email, booking_pattern, timezone, status) VALUES (?, ?, ?, 'catalog@example.test', ?, 'Europe/Amsterdam', 'active')", [$id, 'Catalog ' . $pattern, 'catalog-' . strtolower($id), $pattern]);
            $this->businesses[$pattern] = $id;
        }
        $this->actor = new ActorContext('operator', TestFixtures::OPERATOR_ID, 'operator', array_values($this->businesses), ['services:read', 'services:manage', 'staff:read', 'staff:manage', 'resources:read', 'resources:manage', 'capacity:read', 'capacity:manage', 'events:read', 'events:manage', 'availability:read', 'availability:manage']);
    }

    protected function tearDown(): void
    {
        foreach ($this->businesses as $id) {
            Database::execute('DELETE FROM tenants WHERE id = ?', [$id]);
        }
    }

    public function testSharedCatalogOperationsExist(): void
    {
        self::assertTrue(class_exists(CatalogService::class));
    }

    public function testSharedCatalogRejectsRetainedActorAfterCredentialRotationAndRevocation(): void
    {
        $business = $this->businesses['timeslot'];
        $session = new ActorContext('operator', TestFixtures::OPERATOR_ID, 'operator', [$business], ['services:read', 'services:manage'], authMode: 'session');
        $connections = new \App\Agent\ConnectionService(new \App\Agent\OperationRegistry(...CatalogOperations::definitions()));
        $grant = $connections->createGrant($session, 'Catalog review', $session->scopes, [$business], gmdate('Y-m-d H:i:s', time()+3600), 'Verify current credential');
        $credential = $connections->issueCredential($grant['id'], 'https://fixture.invalid/mcp', gmdate('Y-m-d H:i:s', time()+600));
        $retained = (new \App\Agent\ConnectionAuthenticator())->authenticate($credential['raw_token'], 'https://fixture.invalid/mcp');
        $replacement = $connections->rotate($session, $grant['id'], 'https://fixture.invalid/mcp');
        foreach (['rotation', 'revocation'] as $state) {
            if ($state === 'revocation') {
                $retained = (new \App\Agent\ConnectionAuthenticator())->authenticate($replacement['raw_token'], 'https://fixture.invalid/mcp');
                $connections->revoke($session, $grant['id']);
            }
            try {
                CatalogService::create($retained, 'services', ['name' => 'Must not persist', 'duration_minutes' => 30]);
                self::fail('A retained actor must not outlive ' . $state);
            } catch (OperationException $error) {
                self::assertSame('unauthorized', $error->problemCode);
            }
        }
        self::assertSame(0, (int) Database::query('SELECT COUNT(*) AS c FROM services WHERE tenant_id = ?', [$business])[0]['c']);
    }

    public function testCapacityEditsAndDeletePreserveOccupiedWindowsIncludingLegacyReservations(): void
    {
        $business = $this->businesses['capacity'];
        $slot = CatalogService::create($this->actor, 'capacity_slots', ['business_id'=>$business,'day_of_week'=>1,'start_time'=>'18:00','end_time'=>'20:00','max_capacity'=>12,'max_party_size'=>6]);
        $customer = Ulid::generate();
        Database::execute("INSERT INTO customers (id,tenant_id,name,email) VALUES (?,?,'Synthetic','capacity@example.test')", [$customer,$business]);
        foreach ([['confirmed',$slot['id'],null],['pending',$slot['id'],null],['rescheduled',null,null],['rescheduled',null,Ulid::generate()]] as [$status,$slotId,$replacement]) {
            Database::execute("INSERT INTO bookings (id,tenant_id,customer_id,booking_pattern,capacity_slot_id,start_datetime,end_datetime,status,party_size,rescheduled_to_id) VALUES (?,?,?,'capacity',?,'2027-06-01 18:00:00','2027-06-01 20:00:00',?,2,?)", [Ulid::generate(),$business,$customer,$slotId,$status,$replacement]);
        }
        $base = ['business_id'=>$business,'id'=>$slot['id'],'expected_version'=>$slot['version']];
        foreach ([['max_capacity'=>5,'max_party_size'=>5],['day_of_week'=>2],['start_time'=>'17:00']] as $change) {
            foreach (['preview','commit'] as $phase) {
                try {
                    if ($phase === 'preview') CatalogService::preview($this->actor,'capacity_slots','update',$base+$change);
                    else CatalogService::update($this->actor,'capacity_slots',$base+$change);
                    self::fail('Occupied capacity must remain valid during ' . $phase);
                } catch (OperationException $error) {
                    self::assertContains($error->problemCode,['capacity_conflict','record_in_use']);
                }
            }
        }
        foreach (['preview','commit'] as $phase) {
            try {
                if ($phase === 'preview') CatalogService::preview($this->actor,'capacity_slots','delete',$base);
                else CatalogService::delete($this->actor,'capacity_slots',$base);
                self::fail('Occupied capacity must not be deleted during ' . $phase);
            } catch (OperationException $error) { self::assertSame('record_in_use',$error->problemCode); }
        }
        // Replaced historical origins do not reserve seats; six seats remain valid.
        $updated = CatalogService::update($this->actor,'capacity_slots',$base+['max_capacity'=>6]);
        self::assertSame(6,$updated['max_capacity']);
        Database::execute("UPDATE bookings SET status='cancelled' WHERE tenant_id=?",[$business]);
        self::assertTrue(CatalogService::delete($this->actor,'capacity_slots',['business_id'=>$business,'id'=>$slot['id'],'expected_version'=>$updated['version']])['deleted']);
    }

    public function testAuditFailureRollsBackBrowserAndAgentCatalogChanges(): void
    {
        $business = $this->businesses['timeslot'];
        $actor = new ActorContext('operator', TestFixtures::OPERATOR_ID, 'operator', [$business], ['services:manage'], authMode: 'session');
        $executor = new \App\Agent\Executor(new \App\Agent\OperationRegistry(...CatalogOperations::definitions()));
        Database::execute("ALTER TABLE audit_log ADD CONSTRAINT vb_test_catalog_audit CHECK (tenant_id IS NULL OR tenant_id <> '" . $business . "')");
        try {
            foreach (['browser', 'agent'] as $transport) {
                $input = ['business_id' => $business, 'name' => 'Must roll back ' . $transport, 'duration_minutes' => 30];
                try {
                    if ($transport === 'browser') {
                        CatalogService::create($actor, 'services', $input);
                    } else {
                        $executor->execute($actor, 'service_create', $input, 'audit-failure-' . Ulid::generate());
                    }
                    self::fail('The write must not succeed without audit evidence.');
                } catch (OperationException $error) {
                    self::assertSame('audit_unavailable', $error->problemCode);
                }
                self::assertSame(0, (int) Database::query('SELECT COUNT(*) AS c FROM services WHERE tenant_id = ?', [$business])[0]['c']);
            }
        } finally {
            Database::execute('ALTER TABLE audit_log DROP CHECK vb_test_catalog_audit');
        }
    }

    public function testServiceCreationAndUpdatePreserveAssignmentsAndAuditActor(): void
    {
        $staff = CatalogService::create($this->actor, 'staff', ['business_id' => $this->businesses['timeslot'], 'name' => 'Sam', 'email' => 'sam@example.test']);
        $service = CatalogService::create($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'name' => 'Haircut', 'duration_minutes' => 30, 'staff_ids' => [$staff['id']]]);
        self::assertSame([$staff['id']], $service['staff_ids']);
        $updated = CatalogService::update($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $service['id'], 'expected_version' => $service['version'], 'name' => 'Long haircut']);
        self::assertSame(30, $updated['duration_minutes']);
        self::assertSame([$staff['id']], $updated['staff_ids']);
        self::assertNotSame($service['version'], $updated['version']);
        $audit = Database::query('SELECT actor_type, actor_id, tenant_id FROM audit_log WHERE entity_id = ? ORDER BY created_at DESC LIMIT 1', [$service['id']])[0];
        self::assertSame(TestFixtures::OPERATOR_ID, $audit['actor_id']);
        self::assertSame($this->businesses['timeslot'], $audit['tenant_id']);
    }

    public function testStaleVersionCannotOverwriteAnotherEdit(): void
    {
        $item = $this->service();
        CatalogService::update($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $item['id'], 'expected_version' => $item['version'], 'name' => 'First edit']);
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('version_conflict');
        CatalogService::update($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $item['id'], 'expected_version' => $item['version'], 'name' => 'Stale edit']);
    }

    public function testAssignedRecordsMustBelongToBusiness(): void
    {
        $foreign = Ulid::generate();
        Database::execute('INSERT INTO staff (id, tenant_id, name, email) VALUES (?, ?, ?, ?)', [$foreign, TestFixtures::BUSINESS_TENANT_ID, 'Foreign staff', 'foreign@example.test']);
        $this->expectException(OperationException::class);
        CatalogService::create($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'name' => 'Haircut', 'duration_minutes' => 30, 'staff_ids' => [$foreign]]);
    }

    public function testManagerCannotManageCatalogEvenWithManageScope(): void
    {
        $id = Ulid::generate();
        Database::execute("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role, is_active) VALUES (?, ?, 'Manager', ?, 'synthetic', 'manager', 1)", [$id, $this->businesses['timeslot'], strtolower($id) . '@example.test']);
        $actor = new ActorContext('business_user', $id, 'business_owner', [$this->businesses['timeslot']], ['services:manage']);
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('forbidden');
        CatalogService::create($actor, 'services', ['name' => 'Haircut', 'duration_minutes' => 30]);
    }

    public function testWrongBusinessRecordIsNotDisclosed(): void
    {
        $item = $this->service();
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('not_found');
        CatalogService::get($this->actor, 'services', ['business_id' => $this->businesses['resource'], 'id' => $item['id']]);
    }

    public function testResourceSeasonalRangesCannotOverlapAndRemainAtomic(): void
    {
        $resource = CatalogService::create($this->actor, 'resources', ['business_id' => $this->businesses['resource'], 'name' => 'Room', 'capacity' => 2, 'price_per_night' => '90.00', 'seasonal_pricing' => [['start_date' => '2027-06-01', 'end_date' => '2027-06-30', 'price_per_night' => '120.00']]]);
        self::assertCount(1, $resource['seasonal_pricing']);
        try {
            CatalogService::update($this->actor, 'resources', ['business_id' => $this->businesses['resource'], 'id' => $resource['id'], 'expected_version' => $resource['version'], 'name' => 'Should not persist', 'seasonal_pricing' => [
                ['start_date' => '2027-06-01', 'end_date' => '2027-06-30', 'price_per_night' => '120.00'],
                ['start_date' => '2027-06-15', 'end_date' => '2027-07-01', 'price_per_night' => '130.00'],
            ]]);
            self::fail('Overlapping seasons must be rejected.');
        } catch (OperationException $exception) {
            self::assertSame('validation_failed', $exception->problemCode);
        }
        self::assertSame('Room', CatalogService::get($this->actor, 'resources', ['business_id' => $this->businesses['resource'], 'id' => $resource['id']])['name']);
    }

    public function testCapacityRejectsImpossibleTimeAndPartyLimits(): void
    {
        foreach ([
            ['start_time' => '25:00', 'end_time' => '26:00', 'max_capacity' => 10, 'max_party_size' => 4],
            ['start_time' => '18:00', 'end_time' => '20:00', 'max_capacity' => 3, 'max_party_size' => 4],
        ] as $fields) {
            try {
                CatalogService::create($this->actor, 'capacity_slots', ['business_id' => $this->businesses['capacity'], 'day_of_week' => 1, ...$fields]);
                self::fail('Invalid capacity window must fail.');
            } catch (OperationException $exception) {
                self::assertSame('validation_failed', $exception->problemCode);
            }
        }
    }

    public function testEventRejectsInvalidRecurrenceAndPreservesVenueTimezone(): void
    {
        $event = CatalogService::create($this->actor, 'events', ['business_id' => $this->businesses['event'], 'name' => 'Workshop', 'start_datetime' => '2027-06-05T18:00:00+02:00', 'end_datetime' => '2027-06-05T20:00:00+02:00', 'max_participants' => 8, 'allow_waitlist' => true, 'waitlist_max' => 4]);
        self::assertSame('2027-06-05T18:00:00+02:00', $event['start_datetime']);
        self::assertSame('Europe/Amsterdam', $event['timezone']);
        $this->expectException(OperationException::class);
        CatalogService::update($this->actor, 'events', ['business_id' => $this->businesses['event'], 'id' => $event['id'], 'expected_version' => $event['version'], 'recurrence' => ['frequency' => 'SECONDLY', 'count' => 500000]]);
    }

    public function testScheduleReplaceRejectsOverlapAndSupportsStaffReset(): void
    {
        $business = $this->businesses['timeslot'];
        $staff = CatalogService::create($this->actor, 'staff', ['business_id' => $business, 'name' => 'Sam', 'email' => 'sam@example.test']);
        $schedule = CatalogService::getSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id']]);
        $saved = CatalogService::saveSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id'], 'expected_version' => $schedule['version'], 'windows' => [['day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '12:00']]]);
        self::assertFalse($saved['inherits_business_schedule']);
        $reset = CatalogService::resetSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id'], 'expected_version' => $saved['version']]);
        self::assertTrue($reset['inherits_business_schedule']);
    }

    public function testCapacityBusinessSupportsGlobalBlockAndRejectsStaffTarget(): void
    {
        $block = CatalogService::create($this->actor, 'blocked_dates', ['business_id' => $this->businesses['capacity'], 'start_date' => '2027-06-01', 'end_date' => '2027-06-02', 'reason' => 'Closed']);
        self::assertSame('2027-06-01', $block['start_date']);
        self::assertArrayHasKey('affected_booking_count', $block);
        $this->expectException(OperationException::class);
        CatalogService::create($this->actor, 'blocked_dates', ['business_id' => $this->businesses['capacity'], 'staff_id' => 'foreign', 'start_date' => '2027-06-03', 'end_date' => '2027-06-03']);
    }

    public function testReorderCannotCrossActiveState(): void
    {
        $one = $this->service('One');
        $two = $this->service('Two');
        $inactive = CatalogService::setActive($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $one['id'], 'expected_version' => $one['version'], 'is_active' => false]);
        $moved = CatalogService::reorder($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $two['id'], 'expected_version' => $two['version'], 'direction' => 'up']);
        self::assertSame(0, $moved['sort_order']);
        self::assertFalse($inactive['is_active']);
    }

    public function testEveryPatternResponseMatchesGeneratedRecordSchema(): void
    {
        foreach ([
            'services' => ['business_id' => $this->businesses['timeslot'], 'name' => 'Service', 'duration_minutes' => 30],
            'staff' => ['business_id' => $this->businesses['timeslot'], 'name' => 'Sam', 'email' => 'sam@example.test'],
            'resources' => ['business_id' => $this->businesses['resource'], 'name' => 'Room'],
            'capacity_slots' => ['business_id' => $this->businesses['capacity'], 'day_of_week' => 1, 'start_time' => '18:00', 'end_time' => '20:00', 'max_capacity' => 10, 'max_party_size' => 4],
            'events' => ['business_id' => $this->businesses['event'], 'name' => 'Workshop', 'start_datetime' => '2027-06-05T18:00:00+02:00', 'end_datetime' => '2027-06-05T20:00:00+02:00', 'max_participants' => 8],
            'blocked_dates' => ['business_id' => $this->businesses['capacity'], 'start_date' => '2027-06-01', 'end_date' => '2027-06-02'],
        ] as $kind => $input) {
            $record = CatalogService::create($this->actor, $kind, $input);
            (new SchemaValidator())->validate(CatalogOperations::recordSchema($kind), $record, true);
            self::assertNotEmpty($record['version']);
        }
    }

    public function testStaffOverrideClosedWeekdayDoesNotInheritBusinessHours(): void
    {
        $business = $this->businesses['timeslot'];
        $date = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
        $day = (int) (new \DateTimeImmutable($date))->format('N') - 1;
        $schedule = CatalogService::getSchedule($this->actor, ['business_id' => $business]);
        CatalogService::saveSchedule($this->actor, ['business_id' => $business, 'expected_version' => $schedule['version'], 'windows' => [['day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '12:00']]]);
        $staff = CatalogService::create($this->actor, 'staff', ['business_id' => $business, 'name' => 'Sam', 'email' => 'sam@example.test']);
        $service = CatalogService::create($this->actor, 'services', ['business_id' => $business, 'name' => 'Haircut', 'duration_minutes' => 30, 'staff_ids' => [$staff['id']]]);
        $schedule = CatalogService::getSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id']]);
        CatalogService::saveSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id'], 'expected_version' => $schedule['version'], 'windows' => []]);
        $tenant = Database::query('SELECT * FROM tenants WHERE id = ?', [$business])[0];
        $available = \App\Engine\TimeSlotCalculator::getAvailableSlots($tenant, $date, $service['id'], $staff['id']);
        self::assertSame([], $available['slots']);
    }

    public function testBlockPreviewShowsExistingBookingsWithoutCancellingThem(): void
    {
        $business = $this->businesses['timeslot'];
        $customer = Ulid::generate();
        $booking = Ulid::generate();
        Database::execute("INSERT INTO customers (id, tenant_id, name, email) VALUES (?, ?, 'Synthetic', 'customer@example.test')", [$customer, $business]);
        Database::execute("INSERT INTO bookings (id, tenant_id, customer_id, booking_pattern, start_datetime, end_datetime, status, source) VALUES (?, ?, ?, 'timeslot', '2027-06-01 10:00:00', '2027-06-01 10:30:00', 'confirmed', 'web')", [$booking, $business, $customer]);
        $input = ['business_id' => $business, 'start_date' => '2027-06-01', 'end_date' => '2027-06-01'];
        $preview = CatalogService::preview($this->actor, 'blocked_dates', 'create', $input);
        self::assertSame(1, $preview['affected_booking_count']);
        CatalogService::create($this->actor, 'blocked_dates', $input);
        self::assertSame('confirmed', Database::query('SELECT status FROM bookings WHERE id = ?', [$booking])[0]['status']);
    }

    public function testListCursorDoesNotDuplicateAfterReorderAndRejectsOtherBusiness(): void
    {
        $one = $this->service('One');
        $two = $this->service('Two');
        $first = CatalogService::list($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'limit' => 1]);
        self::assertIsString($first['next_cursor']);
        CatalogService::reorder($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'id' => $two['id'], 'expected_version' => $two['version'], 'direction' => 'up']);
        $next = CatalogService::list($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'limit' => 1, 'cursor' => $first['next_cursor']]);
        self::assertNotSame($first['items'][0]['id'], $next['items'][0]['id']);
        self::assertNull($next['next_cursor']);
        $this->expectException(OperationException::class);
        CatalogService::list($this->actor, 'staff', ['business_id' => $this->businesses['timeslot'], 'cursor' => $first['next_cursor']]);
    }

    public function testInheritedScheduleVersionTracksBusinessHours(): void
    {
        $business = $this->businesses['timeslot'];
        $staff = CatalogService::create($this->actor, 'staff', ['business_id' => $business, 'name' => 'Sam', 'email' => 'sam@example.test']);
        $before = CatalogService::getSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id']]);
        $global = CatalogService::getSchedule($this->actor, ['business_id' => $business]);
        CatalogService::saveSchedule($this->actor, ['business_id' => $business, 'expected_version' => $global['version'], 'windows' => [['day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '12:00']]]);
        $after = CatalogService::getSchedule($this->actor, ['business_id' => $business, 'staff_id' => $staff['id']]);
        self::assertNotSame($before['version'], $after['version']);
    }

    public function testSchedulePreviewCountsExistingBookingsAndDoesNotWrite(): void
    {
        $business = $this->businesses['timeslot'];
        $customer = Ulid::generate();
        Database::execute("INSERT INTO customers (id, tenant_id, name, email) VALUES (?, ?, 'Synthetic', 'customer@example.test')", [$customer, $business]);
        Database::execute("INSERT INTO bookings (id, tenant_id, customer_id, booking_pattern, start_datetime, end_datetime, status, source) VALUES (?, ?, ?, 'timeslot', '2027-06-01 10:00:00', '2027-06-01 10:30:00', 'confirmed', 'web')", [Ulid::generate(), $business, $customer]);
        $before = CatalogService::getSchedule($this->actor, ['business_id' => $business]);
        $preview = CatalogService::previewSchedule($this->actor, ['business_id' => $business, 'expected_version' => $before['version'], 'windows' => []]);
        self::assertSame(1, $preview['affected_booking_count']);
        self::assertTrue($preview['existing_bookings_preserved']);
        self::assertSame($before, CatalogService::getSchedule($this->actor, ['business_id' => $business]));
    }

    public function testConcurrentCatalogUpdatesPermitOnlyOneVersion(): void
    {
        if (!function_exists('pcntl_fork')) { $this->markTestSkipped('Requires process control for concurrency evidence.'); }
        $item = $this->service();
        $directory = sys_get_temp_dir().'/voxelbooking-catalog-race-'.bin2hex(random_bytes(12));
        mkdir($directory,0700);
        Database::reset();
        $children = [];
        for ($i=0;$i<2;$i++) {
            $pid = pcntl_fork();
            if ($pid===0) {
                $deadline = microtime(true)+5;
                while (!is_file($directory.'/go') && microtime(true)<$deadline) { usleep(10000); }
                try {
                    CatalogService::update($this->actor,'services',['business_id'=>$this->businesses['timeslot'],'id'=>$item['id'],'expected_version'=>$item['version'],'name'=>'Concurrent '.$i]);
                    $result='committed';
                } catch (OperationException $error) { $result=$error->problemCode; }
                catch (\Throwable $error) { $result=get_class($error).':'.$error->getMessage(); }
                file_put_contents($directory.'/'.$i,$result);
                exit(0);
            }
            if ($pid<0) { self::fail('Could not start a concurrency worker.'); }
            $children[]=$pid;
        }
        touch($directory.'/go');
        foreach ($children as $pid) { pcntl_waitpid($pid,$status); }
        $results=[file_get_contents($directory.'/0'),file_get_contents($directory.'/1')];
        foreach (['0','1','go'] as $file) { unlink($directory.'/'.$file); }
        rmdir($directory);
        sort($results);
        self::assertSame(['committed','version_conflict'],$results);
    }

    public function testEarlierAuthorizationReadCannotHideACommittedCatalogVersion(): void
    {
        if (!function_exists('pcntl_fork')) { $this->markTestSkipped('Requires process control for concurrency evidence.'); }
        $item = $this->service();
        $business = $this->businesses['timeslot'];
        $directory = sys_get_temp_dir().'/voxelbooking-catalog-snapshot-'.bin2hex(random_bytes(12));
        mkdir($directory,0700);
        Database::reset();
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $deadline = microtime(true)+5;
                while (!is_file($directory.'/go') && microtime(true)<$deadline) { usleep(1000); }
                Database::transaction(function () use ($business,$item,$directory): void {
                    // Auth/receipt reads may precede a domain lock in a composed operation.
                    Database::query('SELECT id FROM operators WHERE id=?',[TestFixtures::OPERATOR_ID]);
                    touch($directory.'/snapshot');
                    $deadline = microtime(true)+5;
                    while (!is_file($directory.'/committed') && microtime(true)<$deadline) { usleep(1000); }
                    CatalogService::update($this->actor,'services',['business_id'=>$business,'id'=>$item['id'],'expected_version'=>$item['version'],'name'=>'Stale overwrite']);
                });
                $result = 'committed';
            } catch (OperationException $error) { $result = $error->problemCode; }
            catch (\Throwable $error) { $result = get_class($error).':'.$error->getMessage(); }
            file_put_contents($directory.'/result',$result);
            exit(0);
        }
        if ($pid < 0) { self::fail('Could not start snapshot worker.'); }
        Database::transaction(function () use ($business,$item,$directory): void {
            Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$business]);
            touch($directory.'/go');
            $deadline = microtime(true)+5;
            while (!is_file($directory.'/snapshot') && microtime(true)<$deadline) { usleep(1000); }
            self::assertFileExists($directory.'/snapshot');
            Database::execute('UPDATE services SET name=? WHERE id=? AND tenant_id=?',['Committed edit',$item['id'],$business]);
        });
        touch($directory.'/committed');
        pcntl_waitpid($pid,$status);
        $result = file_get_contents($directory.'/result');
        foreach (['go','snapshot','committed','result'] as $file) { @unlink($directory.'/'.$file); }
        rmdir($directory);
        self::assertSame('version_conflict',$result);
        self::assertSame('Committed edit',Database::query('SELECT name FROM services WHERE id=?',[$item['id']])[0]['name']);
    }

    public function testWaitingCatalogTeamAndSettingsWritersRecheckIdentity(): void
    {
        if (!function_exists('pcntl_fork')) { $this->markTestSkipped('Requires process control for concurrency evidence.'); }
        $business = $this->businesses['timeslot'];
        foreach (['catalog','team','settings'] as $domain) {
            $user = Ulid::generate();
            Database::execute("INSERT INTO business_users (id,tenant_id,name,email,password_hash,role,is_active) VALUES (?,?,'Waiting owner',?,'unused','owner',1)",[$user,$business,strtolower($user).'@example.test']);
            $actor = new ActorContext('business_user',$user,'business_owner',[$business],['services:manage','team:manage','business-settings:read','business-settings:manage'],authMode:'session');
            $settingsBefore = $domain === 'settings' ? \App\Application\Settings\SettingsService::read($actor,$business,'general') : null;
            $directory = sys_get_temp_dir().'/voxelbooking-authority-race-'.bin2hex(random_bytes(12));
            mkdir($directory,0700);
            Database::reset();
            $pid = pcntl_fork();
            if ($pid === 0) {
                $deadline = microtime(true)+5;
                while (!is_file($directory.'/go') && microtime(true)<$deadline) { usleep(1000); }
                try {
                    if ($domain === 'catalog') CatalogService::create($actor,'services',['name'=>'Must not create','duration_minutes'=>30]);
                    elseif ($domain === 'team') \App\Application\Business\TeamService::invite($actor,['name'=>'Must not invite','email'=>strtolower(Ulid::generate()).'@example.test']);
                    else \App\Application\Settings\SettingsService::update($actor,['business_id'=>$business,'expected_version'=>$settingsBefore['version'],'changes'=>['name'=>'Must not update']],'general');
                    $result = 'committed';
                } catch (OperationException $error) { $result = $error->problemCode; }
                catch (\Throwable $error) { $result = get_class($error).':'.$error->getMessage(); }
                file_put_contents($directory.'/result',$result);
                exit(0);
            }
            if ($pid < 0) { self::fail('Could not start authority worker.'); }
            Database::transaction(function () use ($business,$user,$directory,$domain): void {
                Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE',[$business]);
                touch($directory.'/go');
                $blocked = false;
                $deadline = microtime(true)+5;
                do {
                    foreach (Database::query('SHOW FULL PROCESSLIST') as $process) {
                        $sql = (string)($process['Info'] ?? '');
                        if (str_contains($sql,'FROM tenants WHERE id') && str_contains($sql,'FOR UPDATE')) { $blocked = true; break; }
                    }
                    if (!$blocked) usleep(1000);
                } while (!$blocked && microtime(true)<$deadline);
                self::assertTrue($blocked,'Writer must have passed its first authorization check and reached the held business lock.');
                if ($domain === 'settings') Database::execute("UPDATE business_users SET role='manager' WHERE id=?",[$user]);
                else Database::execute('UPDATE business_users SET is_active=0 WHERE id=?',[$user]);
            });
            pcntl_waitpid($pid,$status);
            $result = file_get_contents($directory.'/result');
            foreach (['go','result'] as $file) { @unlink($directory.'/'.$file); }
            rmdir($directory);
            self::assertSame($domain === 'settings' ? 'forbidden' : 'unauthorized',$result,$domain);
        }
        self::assertSame(0,(int)Database::query('SELECT COUNT(*) AS n FROM services WHERE tenant_id=?',[$business])[0]['n']);
        self::assertSame(3,(int)Database::query('SELECT COUNT(*) AS n FROM business_users WHERE tenant_id=?',[$business])[0]['n']);
        self::assertSame('Catalog timeslot',Database::query('SELECT name FROM tenants WHERE id=?',[$business])[0]['name']);
    }

    public function testEventEditCannotRemoveAnOccurrenceWithBookings(): void
    {
        $business=$this->businesses['event'];
        $event=CatalogService::create($this->actor,'events',['business_id'=>$business,'name'=>'Recurring','start_datetime'=>'2027-06-01T10:00:00+02:00','end_datetime'=>'2027-06-01T11:00:00+02:00','max_participants'=>5,'recurrence'=>['frequency'=>'WEEKLY','count'=>4]]);
        $customer=Ulid::generate();
        Database::execute("INSERT INTO customers (id,tenant_id,name,email) VALUES (?,?,'Guest','guest@example.test')",[$customer,$business]);
        Database::execute("INSERT INTO bookings (id,tenant_id,customer_id,event_id,booking_pattern,start_datetime,end_datetime,status,source,party_size) VALUES (?,?,?,?,'event','2027-06-08 10:00:00','2027-06-08 11:00:00','confirmed','web',2)",[Ulid::generate(),$business,$customer,$event['id']]);
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('record_in_use');
        CatalogService::update($this->actor,'events',['business_id'=>$business,'id'=>$event['id'],'expected_version'=>$event['version'],'exception_dates'=>['2027-06-08']]);
    }

    private function service(string $name = 'Haircut'): array
    {
        return CatalogService::create($this->actor, 'services', ['business_id' => $this->businesses['timeslot'], 'name' => $name, 'duration_minutes' => 30]);
    }
}
