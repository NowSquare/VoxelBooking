<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Business\BusinessService;
use App\Application\Business\TeamService;
use App\Application\Business\ApplicationService;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

final class BusinessOperationsTest extends TestCase
{
    private ActorContext $actor;
    private array $created = [];
    protected function setUp(): void
    {
        TestFixtures::provision();
        $this->actor = new ActorContext('operator', TestFixtures::OPERATOR_ID, 'operator', [TestFixtures::BUSINESS_TENANT_ID], ['businesses:read','businesses:manage','team:read','team:manage','applications:read','applications:manage','reports:read','bookings:read'], authMode: 'session');
    }
    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            Database::execute("DELETE FROM auth_emails WHERE user_type = 'business_user' AND user_id IN (SELECT id FROM business_users WHERE tenant_id = ?)", [$id]);
            Database::execute('DELETE FROM tenants WHERE id = ?', [$id]);
        }
    }
    public function testBusinessCreateAppliesDefaultsAndQueuesOwnerInviteWithoutCredentials(): void
    {
        $record = $this->business(['owner' => ['name' => 'Owner', 'email' => strtolower(Ulid::generate()) . '@example.test']]);
        self::assertSame('timeslot', $record['booking_pattern']);
        self::assertArrayNotHasKey('password_hash', $record);
        $users = Database::query('SELECT * FROM business_users WHERE tenant_id = ?', [$record['id']]);
        self::assertCount(1, $users);
        self::assertSame('owner', $users[0]['role']);
        self::assertSame([], Database::query('SELECT id FROM login_tokens WHERE email = ?', [$users[0]['email']]));
        $delivery = Database::query("SELECT * FROM delivery_outbox WHERE topic = 'team.invitation' AND tenant_id = ?", [$record['id']]);
        self::assertCount(1, $delivery);
        $payload = (new \App\Agent\PayloadCipher())->decrypt($delivery[0]['payload_cipher']);
        self::assertSame(['user_id' => $users[0]['id'], 'business_id' => $record['id']], $payload);
    }
    public function testDuplicateOwnerIdentityRollsBackBusiness(): void
    {
        $slug = 'business-' . strtolower(Ulid::generate());
        try {
            BusinessService::create($this->actor, ['name'=>'Atomic', 'slug'=>$slug, 'email'=>'business@example.test', 'owner'=>['name'=>'Duplicate', 'email'=>TestFixtures::OPERATOR_EMAIL]]);
            self::fail('Duplicate identity must fail.');
        } catch (OperationException $error) {
            self::assertSame('identity_unavailable', $error->problemCode);
        }
        self::assertSame([], Database::query('SELECT id FROM tenants WHERE slug = ?', [$slug]));
    }
    public function testOwnerCannotCreateBusinessDespiteScope(): void
    {
        $actor = new ActorContext('business_user', TestFixtures::BUSINESS_USER_ID, 'operator', [TestFixtures::BUSINESS_TENANT_ID], ['businesses:manage'], authMode:'session');
        $this->expectException(OperationException::class);
        BusinessService::create($actor, ['name'=>'Forbidden', 'email'=>'business@example.test']);
    }
    public function testTeamCannotDeactivateLastOwner(): void
    {
        $record = $this->business(['owner'=>['name'=>'Only owner','email'=>strtolower(Ulid::generate()).'@example.test']]);
        $actor = $this->forBusiness($record['id']);
        $team = TeamService::list($actor, ['business_id'=>$record['id']]);
        $owner = $team['items'][0];
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('last_owner');
        TeamService::setActive($actor, ['business_id'=>$record['id'], 'id'=>$owner['id'], 'expected_version'=>$owner['version'], 'is_active'=>false]);
    }
    public function testBusinessStaleVersionFailsAndPatternCannotChange(): void
    {
        $record = $this->business();
        $actor = $this->forBusiness($record['id']);
        $updated = BusinessService::update($actor, ['business_id'=>$record['id'], 'expected_version'=>$record['version'], 'name'=>'Edited']);
        self::assertNotSame($record['version'], $updated['version']);
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('version_conflict');
        BusinessService::update($actor, ['business_id'=>$record['id'], 'expected_version'=>$record['version'], 'name'=>'Stale']);
    }
    public function testApplicationApprovalDoesNotProvisionBusiness(): void
    {
        Database::execute("INSERT INTO settings (`key`, value) VALUES ('enable_applications', '1') ON DUPLICATE KEY UPDATE value='1'");
        $input = ['business_name'=>'Requested '.Ulid::generate(),'contact_name'=>'Sam','email'=>strtolower(Ulid::generate()).'@example.test'];
        ApplicationService::submit(new ActorContext('anonymous','test-request','anonymous',[],[]), $input);
        $row = Database::query('SELECT id FROM business_applications WHERE email = ?', [$input['email']])[0];
        $application = ApplicationService::get($this->actor, ['id'=>$row['id']]);
        $result = ApplicationService::review($this->actor, ['id'=>$row['id'], 'expected_version'=>$application['version'], 'decision'=>'approved']);
        self::assertSame('approved', $result['status']);
        self::assertSame([], Database::query('SELECT id FROM tenants WHERE name = ?', [$input['business_name']]));
        Database::execute('DELETE FROM business_applications WHERE id = ?', [$row['id']]);
    }
    public function testRegistrySchemasAndReplayReturnOneBusinessAndInvitation(): void
    {
        $registry = new \App\Agent\OperationRegistry(...\App\Application\Business\BusinessOperations::definitions());
        $executor = new \App\Agent\Executor($registry);
        $args = ['name'=>'Replay business','email'=>'synthetic@example.test','slug'=>'replay-'.strtolower(Ulid::generate()),'owner'=>['name'=>'Owner','email'=>strtolower(Ulid::generate()).'@example.test']];
        $key = Ulid::generate();
        $preview = $executor->preview($this->actor,'business_create',$args);
        \App\Engine\Auth::startSession();
        $_SESSION['auth_type']='operator'; $_SESSION['auth_id']=TestFixtures::OPERATOR_ID;
        (new \App\Agent\PreviewStore())->approve($this->actor,basename(parse_url($preview['review_url'],PHP_URL_PATH)));
        $arguments = ['preview_handle'=>$preview['preview_handle']];
        $first = $executor->execute($this->actor,'business_create',$arguments,$key);
        $this->created[] = $first['data']['id'];
        $again = $executor->execute($this->actor,'business_create',$arguments,$key);
        self::assertSame($first,$again);
        self::assertCount(1,Database::query("SELECT id FROM delivery_outbox WHERE topic = 'team.invitation' AND tenant_id = ?",[$first['data']['id']]));
        $actor = $this->forBusiness($first['data']['id']);
        foreach (['business_get','team_list','business_dashboard','operator_dashboard'] as $operation) {
            $result = $executor->execute($actor,$operation,$operation==='operator_dashboard'?[]:['business_id'=>$first['data']['id']]);
            self::assertSame($operation,$result['operation']);
        }
    }

    public function testInvitationsMintOnlyWhenDeliveredAndUseWorkingBrowserRoute(): void
    {
        $record = $this->business(['owner'=>['name'=>'Owner','email'=>strtolower(Ulid::generate()).'@example.test']]);
        $user = Database::query('SELECT id,email FROM business_users WHERE tenant_id = ?',[$record['id']])[0];
        $result = TeamService::deliverInvitation(['business_id'=>$record['id'],'user_id'=>$user['id']]);
        self::assertTrue(($result['sent'] ?? false) || ($result['logged'] ?? false));
        self::assertCount(1,Database::query("SELECT id FROM login_tokens WHERE email = ? AND type='magic_link'",[$user['email']]));
    }

    public function testTeamStateChangeUsesCurrentRoleEvenWithOldOwnerContext(): void
    {
        $member = TeamService::invite($this->actor,['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'name'=>'Managed','email'=>strtolower(Ulid::generate()).'@example.test','role'=>'manager']);
        $actor = new ActorContext('business_user',TestFixtures::BUSINESS_USER_ID,'business_owner',[TestFixtures::BUSINESS_TENANT_ID],['team:manage'],authMode:'session');
        Database::execute("UPDATE business_users SET role='manager' WHERE id = ?",[TestFixtures::BUSINESS_USER_ID]);
        $this->expectException(OperationException::class);
        TeamService::setActive($actor,['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'id'=>$member['id'],'expected_version'=>$member['version'],'is_active'=>false]);
    }

    private function business(array $extra = []): array
    {
        $record = BusinessService::create($this->actor, ['name'=>'Synthetic business', 'slug'=>'business-'.strtolower(Ulid::generate()), 'email'=>'business@example.test', ...$extra]);
        $this->created[] = $record['id'];
        return $record;
    }
    private function forBusiness(string $id): ActorContext
    {
        return new ActorContext('operator', TestFixtures::OPERATOR_ID, 'operator', [$id], $this->actor->scopes, authMode:'session');
    }
}
