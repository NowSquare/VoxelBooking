<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\CustomerCapability;
use App\Engine\Database;
use App\Engine\Request;
use PHPUnit\Framework\TestCase;

final class CustomerCapabilityTest extends TestCase
{
    private \PDO $db;
    private mixed $originalPdo;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(Database::class, 'pdo');
        $this->originalPdo = $property->getValue();
        $this->db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $property->setValue(null, $this->db);
        $this->db->exec('CREATE TABLE tenants (id TEXT PRIMARY KEY, slug TEXT, status TEXT)');
        $this->db->exec('CREATE TABLE customers (id TEXT PRIMARY KEY, tenant_id TEXT, is_anonymized INTEGER DEFAULT 0, email TEXT)');
        $this->db->exec('CREATE TABLE bookings (id TEXT PRIMARY KEY, tenant_id TEXT, customer_id TEXT)');
        $this->db->exec('CREATE TABLE customer_capabilities (id TEXT PRIMARY KEY, token_hash TEXT UNIQUE, purpose TEXT, tenant_id TEXT, subject_id TEXT, customer_id TEXT, created_at TEXT, expires_at TEXT, revoked_at TEXT)');
        $this->db->exec("INSERT INTO tenants VALUES ('tenant-a','a','active'),('tenant-b','b','active')");
        $this->db->exec("INSERT INTO customers VALUES ('customer-a','tenant-a',0,'a@example.com'),('customer-b','tenant-b',0,'b@example.com')");
        $this->db->exec("INSERT INTO bookings VALUES ('booking-a','tenant-a','customer-a'),('booking-b','tenant-b','customer-b'),('booking-other','tenant-a','customer-a')");
        $_SERVER = [];
        $_GET = $_POST = $_FILES = [];
        // Capability checks start a session when none is active, and starting one
        // replaces $_SESSION; open it first so each test's session data survives.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            \App\Engine\Auth::startSession();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->originalPdo);
        $_SESSION = [];
    }

    public function testTokensAreIndependent256BitValuesAndOnlyHashesAreStored(): void
    {
        $first = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $second = CustomerCapability::issueManage('tenant-a', 'booking-a');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        self::assertNotSame($first, $second);
        $rows = $this->db->query('SELECT * FROM customer_capabilities')->fetchAll();
        self::assertSame(hash('sha256', $first), $rows[0]['token_hash']);
        self::assertStringNotContainsString($first, json_encode($rows));
        self::assertNotSame('booking-a', $first);
    }

    public function testPurposeTenantAndRecordBoundsAreEnforced(): void
    {
        $token = CustomerCapability::issueManage('tenant-a', 'booking-a');
        self::assertNotNull(CustomerCapability::verify($token, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        self::assertNull(CustomerCapability::verify($token, CustomerCapability::PRIVACY, 'tenant-a', 'customer-a'));
        self::assertNull(CustomerCapability::verify($token, CustomerCapability::MANAGE, 'tenant-b', 'booking-a'));
        self::assertNull(CustomerCapability::verify($token, CustomerCapability::MANAGE, 'tenant-a', 'booking-other'));
        self::assertNull(CustomerCapability::verify('booking-a', CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        self::assertNull(CustomerCapability::verify(hash('sha256', $token), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testPrivacyGrantDoesNotAuthorizeBookingManagement(): void
    {
        $token = CustomerCapability::issuePrivacy('tenant-a', 'customer-a');
        self::assertNotNull(CustomerCapability::verify($token, CustomerCapability::PRIVACY, 'tenant-a', 'customer-a'));
        self::assertNull(CustomerCapability::verify($token, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testExpiryRevocationAndAnonymizationTakeEffectOnEveryUse(): void
    {
        $expired = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $this->db->exec("UPDATE customer_capabilities SET expires_at = '2000-01-01 00:00:00'");
        self::assertNull(CustomerCapability::verify($expired, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        $revoked = CustomerCapability::issueManage('tenant-a', 'booking-a');
        CustomerCapability::revokeForSubject('tenant-a', CustomerCapability::MANAGE, 'booking-a');
        self::assertNull(CustomerCapability::verify($revoked, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        $anonymized = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $this->db->exec("UPDATE customers SET is_anonymized = 1 WHERE id = 'customer-a'");
        self::assertNull(CustomerCapability::verify($anonymized, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testInvalidCrossBusinessIssuanceFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomerCapability::issueManage('tenant-b', 'booking-a');
    }

    public function testHeaderProofIsRequiredAndQueryTokensAreIgnored(): void
    {
        $token = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $_GET['token'] = $token;
        self::assertFalse(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        $_SERVER['HTTP_X_CUSTOMER_CAPABILITY'] = $token;
        self::assertTrue(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        CustomerCapability::revokeForSubject('tenant-a', CustomerCapability::MANAGE, 'booking-a');
        self::assertFalse(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testArchivedBusinessInvalidatesExistingGrants(): void
    {
        $token = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $this->db->exec("UPDATE tenants SET status = 'archived' WHERE id = 'tenant-a'");
        self::assertNull(CustomerCapability::verify($token, CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testBrowserDigestGrantRechecksRevocationAndNeverAllowsAnotherBooking(): void
    {
        $token = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $key = hash('sha256', CustomerCapability::MANAGE . ':tenant-a:booking-a');
        $_SESSION['customer_capabilities'][$key] = hash('sha256', $token);
        self::assertTrue(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
        self::assertFalse(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-other'));
        self::assertFalse(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::PRIVACY, 'tenant-a', 'customer-a'));
        CustomerCapability::revokeForSubject('tenant-a', CustomerCapability::MANAGE, 'booking-a');
        self::assertFalse(CustomerCapability::requestAuthorized(new Request(), CustomerCapability::MANAGE, 'tenant-a', 'booking-a'));
    }

    public function testSensitiveResponsesForbidCachingAndReferrers(): void
    {
        $response = CustomerCapability::protect(\App\Engine\Response::json(['private' => true]));
        self::assertSame('no-store, private', $response->getHeaders()['Cache-Control']);
        self::assertSame('no-referrer', $response->getHeaders()['Referrer-Policy']);
    }

    public function testReadOnlyResourceIdentifiersCannotReadOrCancelABooking(): void
    {
        \App\Engine\Locale::init(dirname(__DIR__, 3));
        $_SERVER['HTTP_ORIGIN'] = 'http://localhost';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $request = new Request();
        $request->setAttribute('slug', 'a');
        $request->setAttribute('id', 'booking-a');
        $controller = new \App\Controllers\Booking\BookingApiController();
        self::assertSame(403, $controller->bookingDetail($request)->getStatusCode());
        self::assertSame(403, $controller->cancelBookingAction($request)->getStatusCode());
        self::assertSame(403, $controller->rescheduleBookingAction($request)->getStatusCode());
        self::assertStringNotContainsString('a@example.com', $controller->bookingDetail($request)->getBody());
    }

    public function testUrlsPutSecretsInFragmentsAndKeepTheTwoPurposesSeparate(): void
    {
        $token = CustomerCapability::issueManage('tenant-a', 'booking-a');
        $url = CustomerCapability::link('a', CustomerCapability::MANAGE, 'booking-a', $token);
        self::assertSame('/book/a/manage/booking-a#access=' . $token, $url);
        self::assertNull(parse_url($url, PHP_URL_QUERY));
        self::assertSame('/book/a/privacy/customer-a#access=' . $token, CustomerCapability::link('a', CustomerCapability::PRIVACY, 'customer-a', $token));
    }
}
