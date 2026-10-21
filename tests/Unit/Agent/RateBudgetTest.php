<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\RateBudget;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateBudgetTest extends TestCase
{
    private PDO $db;
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE agent_rate_budgets (budget_key TEXT, window_start INTEGER, attempts INTEGER NOT NULL, expires_at TEXT, PRIMARY KEY(budget_key,window_start))');
    }

    public function testBudgetPersistsAcrossLimiterInstancesAndRotatingIps(): void
    {
        $budgets = ['credential:key1' => 2, 'principal:owner1:business:one' => 3];
        self::assertTrue((new RateBudget($this->db))->consume($budgets, 100)['allowed']);
        self::assertTrue((new RateBudget($this->db))->consume($budgets, 101)['allowed']);
        $denied = (new RateBudget($this->db))->consume($budgets, 102);
        self::assertFalse($denied['allowed']);
        self::assertSame(18, $denied['retry_after']);
        self::assertTrue((new RateBudget($this->db))->consume($budgets, 120)['allowed']);
    }

    public function testPrincipalBudgetCannotBeBypassedByRotatingCredentials(): void
    {
        $limiter = new RateBudget($this->db);
        self::assertTrue($limiter->consume(['credential:a' => 60, 'principal:u' => 1], 100)['allowed']);
        self::assertFalse($limiter->consume(['credential:b' => 60, 'principal:u' => 1], 100)['allowed']);
    }

    public function testStorageFailureNeverReportsEnforcementSucceeded(): void
    {
        $this->db->exec('DROP TABLE agent_rate_budgets');
        $this->expectException(\PDOException::class);
        (new RateBudget($this->db))->consume(['credential:a' => 1], 100);
    }
}
