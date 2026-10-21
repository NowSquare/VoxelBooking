<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\AuditLog;
use App\Engine\CustomerAnonymizer;
use App\Engine\CustomerCapability;
use App\Engine\Database;
use App\Engine\DataExporter;
use App\Engine\Locale;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Public privacy controller for data-subject rights.
 *
 * Per PRD §XIX and .ai/23-VoxelBooking-Legal-Logging.md §7.
 *
 * GET  /book/{slug}/privacy/{customer-id}    → View customer data
 * POST /book/{slug}/privacy/{customer-id}    → Request deletion or export
 *
 * An independent customer.privacy capability is required. Booking grants and
 * customer identifiers do not authorize access. Legacy links enter verification.
 *
 * Action flow:
 * - View: shows what data is held (name, email, booking history, consent)
 * - Export: generates JSON download (GDPR Art. 20)
 * - Delete: logs a deletion request for operator review in admin queue
 */
final class PrivacyController
{
    /**
     * Display customer data for review (Right of Access, GDPR Art. 15).
     */
    public function show(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $customerId = $request->getAttribute('customer_id');

        $tenant = $this->loadTenant($slug);
        if ($tenant === null) {
            return Response::html(__('booking.privacy.not_found'), 404);
        }

        // Resolve locale for this tenant context (sets direction for RTL templates + emails)
        Locale::resolveForBooking($tenant, $request->header('Accept-Language'));

        if (!CustomerCapability::requestAuthorized($request, CustomerCapability::PRIVACY, $tenant['id'], $customerId)) {
            return CustomerAccessController::recoveryPage($tenant, CustomerCapability::PRIVACY, $customerId);
        }
        if ($continuation = CustomerCapability::verifiedContinuation(CustomerCapability::PRIVACY, $tenant['id'], $customerId)) {
            return CustomerCapability::protect(Response::redirect($continuation));
        }

        $actor = CustomerCapability::actor($request, CustomerCapability::PRIVACY, $tenant['id'], $customerId);
        $summary = \App\Application\Privacy\PrivacyService::summary($actor, ['business_id' => $tenant['id']]);
        $customer = $summary['customer'] + ['id' => $customerId, 'deletion_requested_at' => $summary['deletion_requested_at']];
        $bookings = [];
        $cursor = null;
        do {
            $input = ['business_id' => $tenant['id'], 'limit' => 100];
            if ($cursor !== null) {
                $input['cursor'] = $cursor;
            }
            $page = \App\Application\Privacy\PrivacyService::records($actor, $input);
            array_push($bookings, ...$page['bookings']);
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $consentRecords = $this->extractConsentRecords($bookings);

        // Start public session and generate CSRF token for POST forms
        $csrfToken = CsrfMiddleware::generateToken();

        // Audit the access
        AuditLog::log(
            'privacy.data_viewed',
            'customer',
            $customerId,
            ['email_prefix' => AuditLog::hashEmail($customer['email'])],
            $tenant['id'],
            actorType: 'customer',
            actorId: $customerId,
        );

        return CustomerCapability::protect(View::response('booking.privacy', [
            'tenant'         => $tenant,
            'customer'       => $customer,
            'bookings'       => $bookings,
            'consentRecords' => $consentRecords,
            'csrfToken'      => $csrfToken,
            'pageTitle'      => __('booking.privacy.page_title') . ' — ' . $tenant['name'],
        ]));
    }

    /**
     * Handle privacy actions (export or deletion request).
     */
    public function action(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $customerId = $request->getAttribute('customer_id');

        $actionType = $request->string('action');

        $tenant = $this->loadTenant($slug);
        if ($tenant === null) {
            return Response::html(__('booking.privacy.not_found'), 404);
        }

        // Resolve locale for this tenant context (sets direction for RTL templates + emails)
        Locale::resolveForBooking($tenant, $request->header('Accept-Language'));

        if (!CustomerCapability::requestAuthorized($request, CustomerCapability::PRIVACY, $tenant['id'], $customerId)) {
            return CustomerAccessController::recoveryPage($tenant, CustomerCapability::PRIVACY, $customerId);
        }

        $customer = $this->loadCustomer($customerId, $tenant['id']);
        if ($customer === null) {
            return Response::html(__('booking.privacy.not_found'), 404);
        }

        if ((int) ($customer['is_anonymized'] ?? 0) === 1) {
            return Response::redirect("/book/{$slug}/privacy/{$customerId}");
        }

        if ($actionType === 'export') {
            return CustomerCapability::protect($this->handleExport(
                $customer,
                $tenant,
                CustomerCapability::actor($request, CustomerCapability::PRIVACY, $tenant['id'], $customerId),
            ));
        }

        if ($actionType === 'delete') {
            // Demo mode: don't allow actual deletion requests (would corrupt demo data)
            if (\App\Engine\DemoMode::isActive()) {
                return Response::redirect("/book/{$slug}/privacy/{$customerId}");
            }
            return CustomerCapability::protect($this->handleDeletionRequest(
                $customer,
                $tenant,
                $slug,
                CustomerCapability::actor($request, CustomerCapability::PRIVACY, $tenant['id'], $customerId),
            ));
        }

        return Response::redirect("/book/{$slug}/privacy/{$customerId}");
    }

    /**
     * Generate and return a JSON export (Right to Data Portability, GDPR Art. 20).
     */
    private function handleExport(array $customer, array $tenant, \App\Agent\ActorContext $actor): Response
    {
        try {
            $data = \App\Application\Privacy\PrivacyService::export($actor, ['business_id' => $tenant['id']]);
            return Response::json($data)
                ->header('Content-Disposition', 'attachment; filename="voxelbooking-data-' . gmdate('Y-m-d') . '.json"');
        } catch (\Throwable) {
            Logger::error('Customer data export unavailable', ['tenant_id' => $tenant['id']]);
            return Response::html(__('booking.privacy.export_failed'), 500);
        }
    }

    /**
     * Log a deletion request (Right to Erasure, GDPR Art. 17).
     *
     * Sets the deletion_requested_at timestamp on the customer record.
     * The operator reviews pending requests in the admin deletion queue
     * and confirms or rejects each request.
     *
     * Only emits an audit event if a row was actually changed (idempotent).
     */
    private function handleDeletionRequest(array $customer, array $tenant, string $slug, \App\Agent\ActorContext $actor): Response
    {
        try {
            \App\Application\Privacy\PrivacyService::requestDeletion($actor, ['business_id' => $tenant['id']]);
        } catch (\Throwable) {
            Logger::error('Customer deletion request unavailable', ['tenant_id' => $tenant['id']]);
            return Response::html(__('booking.access.unavailable'), 503);
        }
        return View::response('booking.privacy-deletion-requested', [
            'tenant' => $tenant, 'customer' => $customer,
            'pageTitle' => __('booking.privacy.deletion_req_page_title') . ' — ' . $tenant['name'],
        ]);
    }

    // ── Helpers ──

    /**
     * @return array<string, mixed>|null
     */
    private function loadTenant(string $slug): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `tenants` WHERE `slug` = ? AND `status` = \'active\' LIMIT 1',
            [$slug]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCustomer(string $customerId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `customers` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$customerId, $tenantId]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractConsentRecords(array $bookings): array
    {
        $records = [];

        foreach ($bookings as $b) {
            if ($b['consent_given_at'] !== null) {
                $records[] = [
                    'booking_date'       => $b['start_datetime'],
                    'consent_given_at'   => $b['consent_given_at'],
                    'consent_text_shown' => $b['consent_text_shown'],
                ];
            }
        }

        return $records;
    }
}
