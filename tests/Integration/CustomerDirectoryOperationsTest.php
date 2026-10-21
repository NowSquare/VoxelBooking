<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Agent\{OperationException, PrincipalResolver};
use App\Application\Customers\CustomerDirectory;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class CustomerDirectoryOperationsTest extends TestCase
{
    protected function setUp(): void { TestFixtures::provision(); }

    private function actor(): \App\Agent\ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID], ['customers:read', 'customers:export'], authMode:'session');
    }

    public function testDirectoryProjectsOperationalFieldsWithoutConsentOrAuthority(): void
    {
        $page = CustomerDirectory::search($this->actor(), ['business_id'=>TestFixtures::BUSINESS_TENANT_ID, 'search'=>'customer@example.com']);
        self::assertNotEmpty($page['items']);
        self::assertSame(TestFixtures::CUSTOMER_ID, $page['items'][0]['id']);
        self::assertIsInt($page['items'][0]['booking_count']);
        self::assertArrayNotHasKey('consent_text_shown', $page['items'][0]);
        self::assertArrayNotHasKey('privacy_url', $page['items'][0]);
        self::assertArrayNotHasKey('notes', $page['items'][0]);
    }

    public function testCrossBusinessCustomerIsNotExposed(): void
    {
        $this->expectException(OperationException::class);
        CustomerDirectory::detail($this->actor(), 'outside-business', TestFixtures::CUSTOMER_ID);
    }

    public function testRevokedMembershipIsCheckedOnEveryDirectoryRead(): void
    {
        $actor = $this->actor();
        Database::execute('UPDATE business_users SET is_active = 0 WHERE id = ?', [TestFixtures::BUSINESS_USER_ID]);
        try {
            CustomerDirectory::search($actor, ['business_id'=>TestFixtures::BUSINESS_TENANT_ID]);
            self::fail('Inactive membership retained access');
        } catch (OperationException $e) {
            self::assertSame(401, $e->status);
        } finally {
            Database::execute('UPDATE business_users SET is_active = 1 WHERE id = ?', [TestFixtures::BUSINESS_USER_ID]);
        }
    }

    public function testCsvFormulaCellsAreEscapedAndExportIsBounded(): void
    {
        Database::execute('UPDATE customers SET name = ? WHERE id = ?', ['=1+2', TestFixtures::CUSTOMER_ID]);
        try {
            $export = CustomerDirectory::export($this->actor(), ['business_id'=>TestFixtures::BUSINESS_TENANT_ID]);
            self::assertStringContainsString("'=1+2", $export['content']);
            self::assertLessThanOrEqual(1000, $export['row_count']);
            self::assertArrayHasKey('next_cursor', $export);
        } finally {
            Database::execute('UPDATE customers SET name = ? WHERE id = ?', ['Test Customer', TestFixtures::CUSTOMER_ID]);
        }
    }
    public function testStableCursorRejectsCrossQueryAndExcludesNewRows(): void
    {
        $ids=[];
        try {
            for($i=0;$i<3;$i++) {
                $id=\App\Engine\Ulid::generate();$ids[]=$id;
                Database::execute('INSERT INTO customers (id,tenant_id,name,email) VALUES (?,?,?,?)',
                    [$id,TestFixtures::BUSINESS_TENANT_ID,'Cursor fixture '.$i,'cursor-'.$id.'@example.com']);
            }
            $input=['business_id'=>TestFixtures::BUSINESS_TENANT_ID,'search'=>'Cursor fixture','limit'=>1];
            $first=CustomerDirectory::search($this->actor(),$input);
            self::assertNotNull($first['next_cursor']);
            $new=\App\Engine\Ulid::generate();$ids[]=$new;
            Database::execute('INSERT INTO customers (id,tenant_id,name,email) VALUES (?,?,?,?)',
                [$new,TestFixtures::BUSINESS_TENANT_ID,'Cursor fixture later','cursor-'.$new.'@example.com']);
            Database::execute('UPDATE customers SET name=? WHERE id=?',['Cursor fixture renamed',$first['items'][0]['id']]);
            $seen=[$first['items'][0]['id']];$cursor=$first['next_cursor'];
            while($cursor!==null) {
                $page=CustomerDirectory::search($this->actor(),[...$input,'cursor'=>$cursor]);
                $seen=[...$seen,...array_column($page['items'],'id')];$cursor=$page['next_cursor'];
            }
            self::assertCount(3,array_unique($seen));self::assertNotContains($new,$seen);
            $this->expectException(OperationException::class);
            CustomerDirectory::search($this->actor(),[...$input,'search'=>'changed','cursor'=>$first['next_cursor']]);
        }finally {
            foreach($ids as $id) { Database::execute('DELETE FROM customers WHERE id=?',[$id]); }
        }
    }
}
