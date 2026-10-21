<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engine\Database;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Give every test a fresh request budget.
 *
 * All tests reach the loopback server from one address, so login throttles
 * and agent budgets fill up across classes. Whether a test then sees 429
 * would depend on which classes ran before it. A test that checks rate
 * limiting still builds its own budget within the test.
 */
final class FreshRequestBudgets implements Extension, PreparationStartedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(PreparationStarted $event): void
    {
        try {
            Database::execute('DELETE FROM `rate_limits`');
            Database::execute('DELETE FROM `agent_rate_budgets`');
        } catch (\Throwable) {
            // A unit test may have swapped in an in-memory database without these tables.
        }
    }
}
