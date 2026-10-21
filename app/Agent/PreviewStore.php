<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;
use App\Engine\Ulid;

/**
 * Reviewed proposals: the payload an agent may commit once a person approves it.
 *
 * Two secrets protect a proposal. The agent keeps the commit handle. A
 * customer's review link carries a separate review secret in its fragment,
 * which the browser exchanges for a session-bound review. The link cannot
 * commit, and the handle cannot open the review.
 *
 * Approval sources say who approved:
 * - `trusted_browser`: a signed-in staff member in the admin.
 * - `customer_browser`: whoever opened the customer's review link. The agent
 *   relays that link, so the server cannot prove a person rather than the
 *   agent opened it; the approval carries the same weight as the booking form.
 */
final class PreviewStore
{
    public const STAFF_APPROVAL = 'trusted_browser';
    public const CUSTOMER_APPROVAL = 'customer_browser';

    public function create(
        ActorContext $actor,
        OperationDefinition $definition,
        array $arguments,
        array $review,
        ?string $businessId
    ): array {
        $id = Ulid::generate();
        $handle = bin2hex(random_bytes(32));
        // Staff review in the signed-in admin and need no link secret.
        $reviewSecret = $actor->isStaff() ? null : bin2hex(random_bytes(32));
        $cipher = new PayloadCipher();
        Database::execute('INSERT INTO agent_previews (id, handle_hash, review_hash, principal_type, principal_id, connection_id, credential_id, tenant_id, operation_id, request_hash, payload_cipher, review_cipher, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id,
            hash('sha256', $handle),
            $reviewSecret === null ? null : hash('sha256', $reviewSecret),
            $actor->principalType,
            $actor->principalId,
            $actor->connectionId,
            $actor->authMode === 'customer' ? $actor->credentialId : null,
            $businessId,
            $definition->operationId,
            CanonicalJson::hash($review),
            $cipher->encrypt($arguments),
            $cipher->encrypt($review),
            gmdate('Y-m-d H:i:s', time() + 600),
            gmdate('Y-m-d H:i:s')
        ]);
        $url = Endpoints::baseUrl() . ($actor->isStaff() ? '/admin/agent-reviews/' : '/agent-reviews/') . $id;
        // The fragment never reaches server logs; the browser exchanges it for a session-bound review.
        if ($reviewSecret !== null) {
            $url .= '#review=' . $reviewSecret;
        }
        return [
            'preview_handle' => $handle,
            'review' => $review,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
            'review_url' => $url,
            'approval_required' => true,
            'reserves_inventory' => false
        ];
    }

    /** Only a CSRF-protected browser route calls this method. */
    public function approve(ActorContext $reviewer, string $id): void
    {
        if ($reviewer->authMode !== 'session') {
            throw new OperationException('forbidden', 403);
        }
        \App\Application\Shared\Access::transaction(function () use ($reviewer, $id): void {
            $this->lockReview($id);
            $row = $this->forReviewer($reviewer, $id);
            Database::execute(
                'UPDATE agent_previews SET approved_at = ?, approval_source = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), self::STAFF_APPROVAL, $row['id']]
            );
        });
    }

    public function forReviewer(ActorContext $reviewer, string $id): array
    {
        $reviewer = (new ConnectionAuthenticator())->refresh($reviewer);
        $row = Database::query(
            'SELECT * FROM agent_previews WHERE id = ? AND principal_type = ? AND principal_id = ?',
            [$id, $reviewer->principalType, $reviewer->principalId]
        )[0] ?? null;
        if (!$row) {
            throw new OperationException('not_found', 404);
        }
        $this->checkTime($row);
        if ($row['tenant_id'] !== null) {
            $reviewer->business($row['tenant_id']);
        }
        $this->authorizeSource($row);
        $row['review'] = (new PayloadCipher())->decrypt($row['review_cipher']);
        return $row;
    }

    /** A link's review secret is exchanged into a CSRF-protected browser session for one review. */
    public function exchangeBrowserHandle(string $id, string $reviewSecret): void
    {
        $row = Database::query(
            'SELECT * FROM agent_previews WHERE id = ? AND review_hash = ?',
            [$id, hash('sha256', $reviewSecret)]
        )[0] ?? null;
        if (!$row || !in_array($row['principal_type'], ['public', 'customer'], true)) {
            throw new OperationException('not_found', 404);
        }
        $this->checkTime($row);
        $this->authorizeSource($row);
        \App\Engine\Auth::startSession();
        $_SESSION['agent_review_handles'][$id] = $row['review_hash'];
        $_SESSION['agent_review_handles'] = array_slice($_SESSION['agent_review_handles'], -20, null, true);
    }

    public function forCustomerBrowser(string $id): array
    {
        \App\Engine\Auth::startSession();
        $hash = $_SESSION['agent_review_handles'][$id] ?? null;
        $row = is_string($hash)
            ? Database::query('SELECT * FROM agent_previews WHERE id = ? AND review_hash = ?', [$id, $hash])[0] ?? null
            : null;
        if (!$row || !in_array($row['principal_type'], ['public', 'customer'], true)) {
            throw new OperationException('review_link_required', 401, nextAction: 'open_review_link');
        }
        $this->checkTime($row);
        $source = $this->authorizeSource($row);
        if ($row['principal_type'] === 'customer') {
            $purpose = $source->bookingId !== null
                ? \App\Engine\CustomerCapability::MANAGE
                : \App\Engine\CustomerCapability::PRIVACY;
            $subject = $source->bookingId ?? $source->customerId;
            $browser = \App\Engine\CustomerCapability::actor(new \App\Engine\Request(), $purpose, $source->business(), $subject);
            if ($browser === null || $browser->principalId !== $source->principalId) {
                throw new OperationException('customer_verification_required', 401, nextAction: 'verify_customer');
            }
        }
        $row['review'] = (new PayloadCipher())->decrypt($row['review_cipher']);
        return $row;
    }

    public function verificationUrl(string $id): ?string
    {
        $hash = $_SESSION['agent_review_handles'][$id] ?? null;
        $row = is_string($hash)
            ? Database::query(
                "SELECT * FROM agent_previews WHERE id = ? AND review_hash = ? AND principal_type = 'customer'",
                [$id, $hash]
            )[0] ?? null
            : null;
        if (!$row) {
            return null;
        }
        $source = $this->authorizeSource($row);
        $slug = Database::query('SELECT slug FROM tenants WHERE id = ?', [$source->business()])[0]['slug'];
        return \App\Engine\CustomerCapability::link(
            $slug,
            $source->bookingId !== null ? \App\Engine\CustomerCapability::MANAGE : \App\Engine\CustomerCapability::PRIVACY,
            $source->bookingId ?? $source->customerId
        );
    }

    /** Called only after the browser route's CSRF check, never by an operation/tool. */
    public function approveCustomerBrowser(string $id, bool $consent = false): void
    {
        \App\Application\Shared\Access::transaction(function () use ($id, $consent): void {
            $this->lockReview($id);
            $row = $this->forCustomerBrowser($id);
            // Consent is recorded only by the person in the browser, so the payload is re-checked
            // against the reviewed preview before the consent flag is sealed into it.
            if ($row['principal_type'] === 'public' && !empty($row['review']['requires_consent'])) {
                if (!$consent) {
                    throw new OperationException('consent_required', 422);
                }
                $arguments = (new PayloadCipher())->decrypt($row['payload_cipher']);
                $arguments['consent_given'] = true;
                $source = $this->authorizeSource($row);
                $definition = Operations::registry()->get($row['operation_id']);
                $current = ($definition->previewHandler)($source, $arguments);
                if (!hash_equals($row['request_hash'], CanonicalJson::hash($current))) {
                    throw new OperationException('preview_changed', 409, nextAction: 'refresh_preview');
                }
                Database::execute(
                    'UPDATE agent_previews SET payload_cipher = ? WHERE id = ?',
                    [(new PayloadCipher())->encrypt($arguments), $id]
                );
            }
            Database::execute(
                'UPDATE agent_previews SET approved_at = ?, approval_source = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), self::CUSTOMER_APPROVAL, $row['id']]
            );
        });
    }

    /** Use the command lock order so approval cannot race consumption or payload erasure. */
    private function lockReview(string $id): void
    {
        $row = Database::query('SELECT tenant_id FROM agent_previews WHERE id=?', [$id])[0]
            ?? throw new OperationException('not_found', 404);
        if ($row['tenant_id'] !== null) {
            Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$row['tenant_id']]);
        }
        $row = Database::query('SELECT consumed_at FROM agent_previews WHERE id=? FOR UPDATE', [$id])[0]
            ?? throw new OperationException('not_found', 404);
        if ($row['consumed_at'] !== null) {
            throw new OperationException('preview_consumed', 409, nextAction: 'recover_operation');
        }
    }

    private function authorizeSource(array $row): ActorContext
    {
        $registry = Operations::registry();
        $definition = $registry->get($row['operation_id']) ?? throw new OperationException('operation_not_found', 404);
        if ($row['connection_id'] !== null) {
            $actor = (new ConnectionAuthenticator())->refresh(new ActorContext(
                $row['principal_type'],
                $row['principal_id'],
                '',
                [],
                [],
                connectionId: $row['connection_id']
            ));
        } elseif ($row['principal_type'] === 'customer') {
            $actor = \App\Engine\CustomerCapability::actorById($row['credential_id'] ?? '')
                ?? throw new OperationException('unauthorized', 401);
        } elseif ($row['principal_type'] === 'public') {
            $active = Database::query("SELECT id FROM tenants WHERE id = ? AND status = 'active'", [$row['tenant_id']]);
            if (!$active) {
                throw new OperationException('not_found', 404);
            }
            $actor = new ActorContext(
                'public',
                $row['principal_id'],
                'prospective_customer',
                [$row['tenant_id']],
                ['public:read', 'public:book'],
                authMode: 'public'
            );
        } else {
            $actor = SessionActor::resolve($registry);
        }
        (new AuthorizationPolicy())->authorize($actor, $definition, $row['tenant_id']);
        return $actor;
    }

    public function resolve(ActorContext $actor, string $operationId, string $handle, bool $lock = false): array
    {
        $row = Database::query(
            'SELECT * FROM agent_previews WHERE handle_hash = ?' . ($lock ? ' FOR UPDATE' : ''),
            [hash('sha256', $handle)]
        )[0] ?? null;
        // A handle is bound to the exact principal, connection and operation that created it.
        if (!$row
            || $row['principal_type'] !== $actor->principalType
            || $row['principal_id'] !== $actor->principalId
            || $row['connection_id'] !== $actor->connectionId
            || $row['operation_id'] !== $operationId
            || ($actor->authMode === 'customer' && $row['credential_id'] !== $actor->credentialId)) {
            throw new OperationException('not_found', 404);
        }
        if ($row['tenant_id'] !== null) {
            $actor->business($row['tenant_id']);
        }
        $row['arguments'] = (new PayloadCipher())->decrypt($row['payload_cipher']);
        return $row;
    }

    public function verifyForCommit(array $row, array $currentReview): void
    {
        $this->assertCommittable($row);
        if (!hash_equals($row['request_hash'], CanonicalJson::hash($currentReview))) {
            throw new OperationException('preview_changed', 409, nextAction: 'refresh_preview');
        }
        Database::execute('UPDATE agent_previews SET consumed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
    }

    public function assertCommittable(array $row): void
    {
        $this->checkTime($row);
        // Each proposal needs the approval its principal can give: staff in the admin, customers by link.
        $expected = in_array($row['principal_type'], ['public', 'customer'], true) ? self::CUSTOMER_APPROVAL : self::STAFF_APPROVAL;
        if ($row['approved_at'] === null || $row['approval_source'] !== $expected) {
            throw new OperationException('trusted_review_required', 409, nextAction: 'open_review');
        }
        if ($row['consumed_at'] !== null) {
            throw new OperationException('preview_consumed', 409, nextAction: 'recover_operation');
        }
    }

    private function checkTime(array $row): void
    {
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            throw new OperationException('preview_expired', 410, nextAction: 'refresh_preview');
        }
    }
}
