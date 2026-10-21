<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\AuditLog;
use App\Engine\Auth;
use App\Engine\CustomerAnonymizer;
use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Logger;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\View;

/**
 * Operator deletion queue — admin UI for reviewing GDPR deletion requests.
 *
 * Per .ai/23-VoxelBooking-Legal-Logging.md §7 (Right to Erasure):
 * - Customer requests deletion via the privacy endpoint
 * - Request is logged with deletion_requested_at timestamp
 * - Operator reviews pending requests in this queue
 * - Operator can confirm (anonymize) or dismiss each request
 * - All decisions are audit-logged
 */
final class DeletionQueueController
{
    /**
     * Display the deletion request queue.
     */
    public function index(Request $request): Response
    {
        $actor = \App\Agent\SessionActor::resolve(\App\Agent\Operations::registry());
        $pendingRequests = [];
        $processedRequests = [];
        foreach ($actor->businessIds as $businessId) {
            $tenant = Database::query('SELECT name,slug FROM tenants WHERE id=?', [$businessId])[0] ?? null;
            if ($tenant === null) {
                continue;
            }
            foreach (['pending', 'processed'] as $state) {
                $cursor = null;
                do {
                    $input = ['business_id' => $businessId, 'state' => $state, 'limit' => 100];
                    if ($cursor !== null) {
                        $input['cursor'] = $cursor;
                    }
                    $result = \App\Application\Privacy\DeletionService::queue($actor, $input);
                    foreach ($result['requests'] as $row) {
                        $row += ['tenant_name' => $tenant['name'], 'tenant_slug' => $tenant['slug']];
                        if ($state === 'pending') {
                            $row['review'] = \App\Application\Privacy\DeletionService::preview(
                                $actor,
                                ['business_id' => $businessId, 'customer_id' => $row['id']],
                            );
                            $pendingRequests[] = $row;
                        } else {
                            $processedRequests[] = $row;
                        }
                    }
                    $cursor = $result['next_cursor'];
                } while ($cursor !== null);
            }
        }
        usort($pendingRequests, static fn($a, $b) => strcmp($a['deletion_requested_at'], $b['deletion_requested_at']));
        usort($processedRequests, static fn($a, $b) => strcmp($b['anonymized_at'], $a['anonymized_at']));
        return View::response('admin.deletion-queue', [
            'pendingRequests' => $pendingRequests,
            'processedRequests' => array_slice($processedRequests, 0, 25),
            'pageTitle' => __('admin.deletion.page_title'),
            'flash' => FormState::getToast(),
        ]);
    }

    /**
     * Confirm a deletion request — anonymize the customer.
     */
    public function confirm(Request $request): Response
    {
        try {
            $actor = \App\Agent\SessionActor::resolve(\App\Agent\Operations::registry());
            $id = $request->string('customer_id');
            $customer = Database::query('SELECT tenant_id FROM customers WHERE id=?', [$id])[0] ?? null;
            if ($customer === null) {
                throw new \App\Agent\OperationException('not_found', 404);
            }
            $version = $request->string('_review_version');
            if (!preg_match('/^[a-f0-9]{64}$/D', $version)) {
                throw new \App\Agent\OperationException('preview_required', 422);
            }
            \App\Application\Privacy\DeletionService::confirm($actor, [
                'business_id' => $customer['tenant_id'],
                'customer_id' => $id,
                'expected_version' => $version,
            ]);
            FormState::toast('success', __('admin.deletion.anonymized_success'));
        } catch (\App\Agent\OperationException $error) {
            FormState::toast('error', __('agent-booking.deletion.review_again'));
        } catch (\Throwable) {
            Logger::error('Deletion decision unavailable');
            FormState::toast('error', __('admin.deletion.dismiss_failed'));
        }
        return Response::redirect('/admin/deletion-queue');
    }

    /**
     * Dismiss a deletion request — clear the request without anonymizing.
     */
    public function dismiss(Request $request): Response
    {
        try {
            $actor = \App\Agent\SessionActor::resolve(\App\Agent\Operations::registry());
            $id = $request->string('customer_id');
            $customer = Database::query('SELECT tenant_id FROM customers WHERE id=?', [$id])[0] ?? null;
            if ($customer === null) {
                throw new \App\Agent\OperationException('not_found', 404);
            }
            $version = $request->string('_review_version');
            if (!preg_match('/^[a-f0-9]{64}$/D', $version)) {
                throw new \App\Agent\OperationException('preview_required', 422);
            }
            \App\Application\Privacy\DeletionService::dismiss($actor, [
                'business_id' => $customer['tenant_id'],
                'customer_id' => $id,
                'expected_version' => $version,
                'reason' => $request->string('reason'),
            ]);
            FormState::toast('success', __('admin.deletion.dismissed_success'));
        } catch (\App\Agent\OperationException $error) {
            FormState::toast('error', __('agent-booking.deletion.review_again'));
        } catch (\Throwable) {
            Logger::error('Deletion decision unavailable');
            FormState::toast('error', __('admin.deletion.dismiss_failed'));
        }
        return Response::redirect('/admin/deletion-queue');
    }
}
