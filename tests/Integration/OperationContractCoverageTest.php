<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{ActorContext, ConnectionService, Endpoints, Executor, Operations, OperationRegistry, PreviewStore, PrincipalResolver, SchemaValidator};
use App\Application\Catalog\CatalogFields;
use App\Engine\{Database, Locale, Ulid};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Successful real operations, not schema-shaped mocks. Executor validates the
 * handler projection; these assertions also validate the published envelope and
 * compare independently authenticated HTTP and MCP reads with persisted data.
 */
final class OperationContractCoverageTest extends TestCase
{
    private OperationRegistry $registry;
    private Executor $executor;
    private ActorContext $actor;
    private array $businesses = [];
    private array $connections = [];
    private array $settings = [];
    private array $applications = [];
    private array $tokens = [];
    private array $session;

    protected function setUp(): void
    {
        TestFixtures::provision();
        \App\Engine\Auth::startSession();
        $this->session = $_SESSION;
        $_SESSION = ['auth_type'=>'operator', 'auth_id'=>TestFixtures::OPERATOR_ID];
        Locale::init(dirname(__DIR__, 2));
        $this->registry = Operations::registry();
        $this->executor = new Executor($this->registry);
        foreach (['timeslot', 'resource', 'capacity', 'event'] as $pattern) {
            $id = Ulid::generate();
            Database::execute("INSERT INTO tenants (id,name,slug,email,booking_pattern,timezone,status) VALUES (?,?,?,'contract@example.test',?,'UTC','active')",
                [$id, 'Contract '.$pattern, 'contract-'.strtolower($id), $pattern]);
            $this->businesses[$pattern] = $id;
        }
        foreach (['app_name', 'system_settings_revision', 'enable_applications'] as $key) {
            $this->settings[$key] = Database::query('SELECT value FROM settings WHERE `key`=?', [$key])[0]['value'] ?? null;
        }
        $this->refreshActor();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        foreach ($this->connections as $id) {
            Database::execute('UPDATE agent_connections SET revoked_at=NOW() WHERE id=?', [$id]);
        }
        foreach ($this->businesses as $id) {
            Database::execute('DELETE FROM tenants WHERE id=?', [$id]);
        }
        foreach ($this->settings as $key => $value) {
            if ($value === null) {
                Database::execute('DELETE FROM settings WHERE `key`=?', [$key]);
            } else {
                Database::upsertSetting($key, $value);
            }
        }
        foreach ($this->applications as $id) {
            Database::execute('DELETE FROM business_applications WHERE id=?', [$id]);
        }
    }

    private function refreshActor(): void
    {
        $scopes = [];
        foreach ($this->registry->all() as $definition) {
            $scopes = [...$scopes, ...$definition->requiredScopes];
        }
        $this->actor = (new PrincipalResolver())->resolve('operator', TestFixtures::OPERATOR_ID,
            array_values($this->businesses), array_values(array_unique($scopes)), authMode: 'session');
        $this->tokens = [];
    }

    private function invoke(string $name, array $input = [], bool $replay = true): array
    {
        $definition = $this->registry->get($name);
        self::assertNotNull($definition, $name);
        if (!$definition->readOnly && $definition->confirmationPolicy !== 'none') {
            $preview = $this->executor->preview($this->actor, $name, $input);
            self::assertFalse($preview['reserves_inventory'], $name);
            self::assertTrue($preview['approval_required'], $name);
            (new PreviewStore())->approve($this->actor, basename(parse_url($preview['review_url'], PHP_URL_PATH)));
            $input = ['preview_handle' => $preview['preview_handle']];
        }
        $key = $definition->readOnly ? null : Ulid::generate();
        $result = $this->executor->execute($this->actor, $name, $input, $key);
        (new SchemaValidator())->validate($definition->resultSchema(), $result, true);
        self::assertSame($name, $result['operation']);
        if ($definition->readOnly) {
            self::assertNull($result['receipt_id']);
        } else {
            self::assertNotNull($result['receipt_id']);
            if ($replay) {
                self::assertSame($result, $this->executor->execute($this->actor, $name, $input, $key), $name.' replay');
                self::assertSame(1, (int) Database::query('SELECT COUNT(*) AS c FROM agent_operation_receipts WHERE id=?', [$result['receipt_id']])[0]['c']);
            }
        }
        return $result['data'];
    }

    private function assertReadTransports(string $name, array $input, array $expected): void
    {
        foreach (['http', 'mcp'] as $transport) {
            if (!isset($this->tokens[$transport])) {
                $service = new ConnectionService($this->registry);
                $expires = gmdate('Y-m-d H:i:s', time()+3600);
                $scopes = [];
                foreach ($this->registry->visible($this->actor) as $operation) {
                    $scopes = [...$scopes, ...$operation->requiredScopes];
                }
                $grant = $service->createGrant($this->actor, 'Contract '.$transport, array_values(array_unique($scopes)),
                    $this->actor->businessIds, $expires, 'Disposable successful contract verification');
                $this->connections[] = $grant['id'];
                $this->tokens[$transport] = $service->issueCredential($grant['id'], Endpoints::resource($transport), $expires)['raw_token'];
            }
        }
        $definition = $this->registry->get($name);
        $path = preg_replace_callback('/\{([^}]+)\}/', static fn(array $match): string => rawurlencode((string)$input[$match[1]]), $definition->httpPath);
        $response = $this->request('/api/agent/v2'.$path.'?'.http_build_query($input), 'http');
        $native = $this->request('/mcp', 'mcp', [
            'jsonrpc'=>'2.0', 'id'=>42, 'method'=>'tools/call',
            'params'=>['name'=>$name, 'arguments'=>$input === [] ? new \stdClass() : $input],
        ]);
        self::assertFalse($native['result']['isError'] ?? true, json_encode($native));
        $mcp = $native['result']['structuredContent'];
        foreach ([$response, $mcp] as $result) {
            (new SchemaValidator())->validate($definition->resultSchema(), $result, true);
            self::assertSame($name, $result['operation']);
            // These endpoints intentionally include their own observation timestamp.
            $data = $result['data'];
            unset($data['generated_at']);
            $comparable = $expected;
            unset($comparable['generated_at']);
            self::assertSame($comparable, $data, $name);
        }
    }

    private function request(string $path, string $transport, ?array $body = null): array
    {
        $curl = curl_init(Endpoints::baseUrl().$path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->tokens[$transport],
                'Content-Type: application/json', 'Accept: application/json, text/event-stream',
                'MCP-Protocol-Version: 2025-11-25'],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($curl);
        self::assertSame(200, curl_getinfo($curl, CURLINFO_HTTP_CODE), (string)$raw);
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function catalogCases(): array
    {
        return [
            'services'=>['services','timeslot',['name'=>'Contract service','duration_minutes'=>30,'price'=>'12.50'],['name'=>'Updated service']],
            'staff'=>['staff','timeslot',['name'=>'Contract staff','email'=>'contract-staff@example.test'],['title'=>'Instructor']],
            'resources'=>['resources','resource',['name'=>'Contract room','price_per_night'=>'50.00','amenities'=>['Desk']],['capacity'=>4]],
            'capacity'=>['capacity_slots','capacity',['day_of_week'=>1,'start_time'=>'18:00','end_time'=>'20:00','max_capacity'=>12,'max_party_size'=>6],[]],
            'events'=>['events','event',['name'=>'Contract workshop','start_datetime'=>'2027-06-01T18:00:00+00:00','end_datetime'=>'2027-06-01T20:00:00+00:00','max_participants'=>12],['name'=>'Updated workshop']],
            'blocked dates'=>['blocked_dates','timeslot',['start_date'=>'2027-06-10','end_date'=>'2027-06-11','reason'=>'Maintenance'],[]],
        ];
    }

    #[DataProvider('catalogCases')]
    public function testEveryCatalogActionReturnsItsPublishedSuccessContract(string $kind, string $pattern, array $fields, array $changes): void
    {
        $business = $this->businesses[$pattern];
        $entity = CatalogFields::TYPES[$kind]['entity'];
        $created = $this->invoke($entity.'_create', ['business_id'=>$business, ...$fields]);
        self::assertSame($business, $created['business_id']);
        $identity = ['business_id'=>$business, 'id'=>$created['id']];
        $read = $this->invoke($entity.'_get', $identity);
        self::assertSame($created, $read);
        $this->assertReadTransports($entity.'_get', $identity, $read);
        $list = $this->invoke($kind.'_list', ['business_id'=>$business]);
        self::assertSame([$created], $list['items']);
        $this->assertReadTransports($kind.'_list', ['business_id'=>$business], $list);
        $current = $created;
        if ($changes !== []) {
            $current = $this->invoke($entity.'_update', [...$identity, 'expected_version'=>$current['version'], ...$changes]);
            foreach ($changes as $field=>$value) {
                self::assertSame($value, $current[$field]);
            }
        }
        if ($kind !== 'blocked_dates') {
            $current = $this->invoke($entity.'_set_active', [...$identity, 'expected_version'=>$current['version'], 'is_active'=>false]);
            self::assertFalse($current['is_active']);
        }
        if (in_array($kind, ['services','staff','resources'], true)) {
            $secondFields = [...$fields, 'name'=>'Second contract item'];
            if ($kind === 'staff') {
                $secondFields['email'] = 'second-staff@example.test';
            }
            $second = $this->invoke($entity.'_create', ['business_id'=>$business, ...$secondFields]);
            $this->invoke($entity.'_set_active', ['business_id'=>$business, 'id'=>$second['id'], 'expected_version'=>$second['version'], 'is_active'=>false]);
            $current = $this->invoke($entity.'_reorder', [...$identity, 'expected_version'=>$current['version'], 'direction'=>'down']);
            self::assertGreaterThan($created['sort_order'], $current['sort_order']);
            self::assertNotSame($current['id'], $second['id']);
        } else {
            $deleted = $this->invoke($entity.'_delete', [...$identity, 'expected_version'=>$current['version']]);
            self::assertTrue($deleted['deleted']);
            self::assertSame(0, (int)Database::query("SELECT COUNT(*) AS c FROM `$kind` WHERE id=?", [$created['id']])[0]['c']);
        }
    }

    public function testAllScheduleActionsUseVersionedContractsAndTransportParity(): void
    {
        $business = $this->businesses['timeslot'];
        $staff = $this->invoke('staff_create', ['business_id'=>$business, 'name'=>'Schedule staff', 'email'=>'schedule@example.test']);
        $input = ['business_id'=>$business];
        $schedule = $this->invoke('schedule_get', $input);
        $windows = [['day_of_week'=>1, 'start_time'=>'08:00', 'end_time'=>'12:00']];
        $businessSchedule = $this->invoke('schedule_replace', [...$input, 'expected_version'=>$schedule['version'], 'windows'=>$windows]);
        self::assertCount(1, $businessSchedule['windows']);
        $input['staff_id'] = $staff['id'];
        $staffSchedule = $this->invoke('schedule_get', $input);
        self::assertTrue($staffSchedule['inherits_business_schedule']);
        $custom = $this->invoke('schedule_replace', [...$input, 'expected_version'=>$staffSchedule['version'],
            'windows'=>[['day_of_week'=>2,'start_time'=>'09:00','end_time'=>'11:00']]]);
        self::assertFalse($custom['inherits_business_schedule']);
        $reset = $this->invoke('schedule_reset_staff', [...$input, 'expected_version'=>$custom['version']]);
        self::assertTrue($reset['inherits_business_schedule']);
        self::assertSame($businessSchedule['windows'], $reset['windows']);
        $this->assertReadTransports('schedule_get', $input, $reset);
    }

    public function testEveryBusinessAndTeamActionUsesPublishedContractsWithoutImplicitProvisioning(): void
    {
        $business = $this->businesses['timeslot'];
        foreach (['businesses_list','businesses_export','operator_dashboard'] as $name) {
            $this->assertReadTransports($name, [], $this->invoke($name));
        }
        $record = $this->invoke('business_get', ['business_id'=>$business]);
        $this->assertReadTransports('business_get', ['business_id'=>$business], $record);
        $this->assertReadTransports('business_dashboard', ['business_id'=>$business], $this->invoke('business_dashboard', ['business_id'=>$business]));
        $updated = $this->invoke('business_update', ['business_id'=>$business, 'expected_version'=>$record['version'], 'name'=>'Updated contract business']);
        self::assertSame('Updated contract business', $updated['name']);
        $created = $this->invoke('business_create', ['name'=>'New contract business', 'email'=>'new-business@example.test', 'booking_pattern'=>'resource']);
        self::assertSame('resource', $created['booking_pattern']);
        $this->businesses['new'] = $created['id'];
        $this->refreshActor();
        $member = $this->invoke('team_invite', ['business_id'=>$business,'name'=>'Contract manager','email'=>'manager-'.strtolower(Ulid::generate()).'@example.test','role'=>'manager']);
        self::assertTrue($member['is_active']);
        $memberInput = ['business_id'=>$business,'id'=>$member['id']];
        $this->assertReadTransports('team_member_get', $memberInput, $this->invoke('team_member_get', $memberInput));
        $this->assertReadTransports('team_list', ['business_id'=>$business], $this->invoke('team_list', ['business_id'=>$business]));
        $disabled = $this->invoke('team_member_set_active', [...$memberInput,'expected_version'=>$member['version'],'is_active'=>false]);
        self::assertFalse($disabled['is_active']);
        Database::upsertSetting('enable_applications', '1');
        $email = 'applicant-'.strtolower(Ulid::generate()).'@example.test';
        self::assertSame(['accepted'=>true,'status'=>'received'], $this->invoke('application_submit', ['business_name'=>'Requested business','contact_name'=>'Applicant','email'=>$email]));
        $id = Database::query('SELECT id FROM business_applications WHERE email=?', [$email])[0]['id'];
        $this->applications[] = $id;
        $request = $this->invoke('application_get', ['id'=>$id]);
        $this->assertReadTransports('application_get', ['id'=>$id], $request);
        $this->assertReadTransports('applications_list', [], $this->invoke('applications_list'));
        $countBefore = (int)Database::query('SELECT COUNT(*) AS c FROM tenants')[0]['c'];
        $approved = $this->invoke('application_review', ['id'=>$id,'expected_version'=>$request['version'],'decision'=>'approved','admin_notes'=>'Reviewed separately from provisioning']);
        self::assertSame('approved', $approved['status']);
        self::assertSame($countBefore, (int)Database::query('SELECT COUNT(*) AS c FROM tenants')[0]['c']);
        $archived = $this->invoke('business_set_status', ['business_id'=>$business,'expected_version'=>$updated['version'],'status'=>'archived'], replay:false);
        self::assertSame('archived', $archived['status']);
    }

    public function testAllOperatorSystemActionsUsePublishedNonsecretSuccessContracts(): void
    {
        $general = $this->invoke('system_settings_get', ['section'=>'general']);
        $this->assertReadTransports('system_settings_get', ['section'=>'general'], $general);
        $mail = $this->invoke('system_settings_get', ['section'=>'mail']);
        self::assertArrayNotHasKey('smtp_password', $mail['values']);
        $this->assertReadTransports('system_settings_get', ['section'=>'mail'], $mail);
        $updated = $this->invoke('system_settings_update', ['section'=>'general','expected_version'=>$general['version'],'changes'=>['app_name'=>'Contract installation']]);
        self::assertSame('Contract installation', $updated['values']['app_name']);
        foreach (['system_status','jobs_status','audit_list','audit_export'] as $name) {
            $this->assertReadTransports($name, [], $this->invoke($name));
        }
        $input = ['business_id'=>$this->businesses['timeslot']];
        $context = $this->invoke('operator_business_context', $input);
        self::assertFalse($context['browser_session_changed']);
        $this->assertReadTransports('operator_business_context', $input, $context);
        foreach (['mail_credentials','cron_credentials','raw_logs','update','installation'] as $task) {
            $handoff = $this->invoke('system_handoff', ['task'=>$task]);
            self::assertSame($task, $handoff['task']);
            $this->assertReadTransports('system_handoff', ['task'=>$task], $handoff);
        }
        $job = $this->invoke('jobs_run', ['jobs'=>['outbox_cleanup']]);
        self::assertSame('queued', $job['status']);
        $this->assertReadTransports('job_request_get', ['id'=>$job['id']], $this->invoke('job_request_get', ['id'=>$job['id']]));
        Database::execute("UPDATE system_job_requests SET status='cancelled',finished_at=NOW() WHERE id=?", [$job['id']]);
    }
}
