<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\ActorContext;
use App\Agent\Executor;
use App\Agent\OperationDefinition;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\PreviewStore;
use App\Agent\PrincipalResolver;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * A reviewed commit's receipt outlives its preview.
 *
 * Previews are short-lived and cleaned up; receipts replay for 24 hours. A
 * client that lost the response and retries with the same key must get the
 * stored result, not a 404 for the preview that was already used.
 */
final class AgentReceiptReplayTest extends TestCase
{
    protected function setUp(): void
    {
        TestFixtures::provision();
        \Tests\Support\CustomerFixtureState::restore();
    }

    protected function tearDown(): void
    {
        Database::execute(
            "DELETE FROM services WHERE tenant_id = ? AND name IN ('Replay after cleanup', 'Vanished before commit', 'Owner only')",
            [TestFixtures::BUSINESS_TENANT_ID]
        );
    }

    public function test_replay_returns_the_stored_receipt_after_the_preview_is_gone(): void
    {
        $executor = new Executor($this->registry());
        $actor = $this->actor();
        $handle = $this->approvedPreview($executor, $actor, 'Replay after cleanup');
        $key = 'replay-' . Ulid::generate();

        $first = $executor->execute($actor, 'fixture_reviewed_create', ['preview_handle' => $handle], $key);
        Database::execute('DELETE FROM agent_previews WHERE handle_hash = ?', [hash('sha256', $handle)]);
        $replay = $executor->execute($actor, 'fixture_reviewed_create', ['preview_handle' => $handle], $key);

        $this->assertSame($first, $replay);
        $this->assertSame(1, (int) Database::query('SELECT COUNT(*) AS n FROM services WHERE id = ?', [$first['data']['id']])[0]['n']);
    }

    public function test_a_new_key_for_a_vanished_preview_is_still_not_found(): void
    {
        $executor = new Executor($this->registry());
        $actor = $this->actor();
        $handle = $this->approvedPreview($executor, $actor, 'Vanished before commit');
        Database::execute('DELETE FROM agent_previews WHERE handle_hash = ?', [hash('sha256', $handle)]);

        try {
            $executor->execute($actor, 'fixture_reviewed_create', ['preview_handle' => $handle], 'unused-' . Ulid::generate());
            $this->fail('A vanished preview without a receipt must not commit.');
        } catch (OperationException $error) {
            $this->assertSame(404, $error->status);
        }
    }

    public function test_another_principal_cannot_replay_someone_elses_receipt(): void
    {
        $executor = new Executor($this->registry());
        $handle = $this->approvedPreview($executor, $this->actor(), 'Owner only');
        $key = 'replay-' . Ulid::generate();
        $executor->execute($this->actor(), 'fixture_reviewed_create', ['preview_handle' => $handle], $key);
        Database::execute('DELETE FROM agent_previews WHERE handle_hash = ?', [hash('sha256', $handle)]);
        $operator = (new PrincipalResolver())->resolve('operator', TestFixtures::OPERATOR_ID, [TestFixtures::BUSINESS_TENANT_ID], ['services:manage'], authMode: 'session');

        $this->expectException(OperationException::class);
        $executor->execute($operator, 'fixture_reviewed_create', ['preview_handle' => $handle], $key);
    }

    private function approvedPreview(Executor $executor, ActorContext $actor, string $name): string
    {
        $preview = $executor->preview($actor, 'fixture_reviewed_create', ['business_id' => TestFixtures::BUSINESS_TENANT_ID, 'name' => $name]);
        // Stands in for the signed-in staff approval page.
        Database::execute(
            'UPDATE agent_previews SET approved_at = UTC_TIMESTAMP(), approval_source = ? WHERE handle_hash = ?',
            [PreviewStore::STAFF_APPROVAL, hash('sha256', $preview['preview_handle'])]
        );

        return $preview['preview_handle'];
    }

    private function actor(): ActorContext
    {
        return (new PrincipalResolver())->resolve('business_user', TestFixtures::BUSINESS_USER_ID, [TestFixtures::BUSINESS_TENANT_ID], ['services:manage'], authMode: 'session');
    }

    private function registry(): OperationRegistry
    {
        $input = [
            'type' => 'object',
            'required' => ['business_id', 'name'],
            'properties' => ['business_id' => ['type' => 'string'], 'name' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $output = ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]];

        return new OperationRegistry(new OperationDefinition(
            'fixture_reviewed_create',
            'Reviewed fixture',
            'Synthetic reviewed command',
            $input,
            $output,
            ['services:manage'],
            'owner',
            false,
            false,
            'trusted',
            ['timeslot'],
            'fixture',
            ['C12'],
            ['A13'],
            'POST',
            '/fixture',
            static function ($actor, $arguments): array {
                $id = Ulid::generate();
                Database::execute(
                    'INSERT INTO services (id, tenant_id, name, duration_minutes) VALUES (?, ?, ?, 30)',
                    [$id, $arguments['business_id'], $arguments['name']]
                );

                return ['id' => $id];
            },
            previewHandler: static fn ($actor, $arguments): array => ['name' => $arguments['name']]
        ));
    }
}
