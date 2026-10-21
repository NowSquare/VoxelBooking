<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\AuditLog;
use App\Engine\CustomerCapability;
use App\Engine\CustomerCapabilityRecovery;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Logger;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/** Email verification and fragment exchange for independent customer grants. */
final class CustomerAccessController
{
    public static function recoveryPage(array $tenant, string $purpose, string $subjectId, bool $accepted = false): Response
    {
        \App\Engine\Auth::startSession();
        return CustomerCapability::protect(View::response('booking.access-recovery', [
            'tenant' => $tenant, 'purpose' => $purpose, 'subjectId' => $subjectId,
            'accepted' => $accepted, 'csrfToken' => CsrfMiddleware::generateToken(),
        ]));
    }

    public function exchange(Request $request): Response
    {
        \App\Engine\Auth::startSession();
        if (!CsrfMiddleware::tokenMatches($request)) {
            return CustomerCapability::protect(Response::json(['error' => 'csrf_mismatch'], 403));
        }
        $body = $request->json();
        $purpose = is_string($body['purpose'] ?? null) ? $body['purpose'] : '';
        $subjectId = is_string($body['subject_id'] ?? null) ? $body['subject_id'] : '';
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        $tenant = $this->tenant((string) $request->getAttribute('slug'));
        if ($tenant === null || !CustomerCapability::remember($token, $purpose, $tenant['id'], $subjectId)) {
            return CustomerCapability::protect(Response::json(['error' => 'verification_required'], 403));
        }
        AuditLog::log(
            'customer.access_verified',
            'customer_capability',
            null,
            ['purpose' => $purpose, 'subject_id' => $subjectId],
            $tenant['id'],
            actorType: 'customer',
            actorId: null,
        );
        return CustomerCapability::protect(Response::json(['verified' => true]));
    }

    public function recover(Request $request): Response
    {
        $slug = (string) $request->getAttribute('slug');
        $tenant = $this->tenant($slug);
        $purpose = $request->string('purpose');
        $subjectId = $request->string('subject_id');
        if (!in_array($purpose, [CustomerCapability::MANAGE, CustomerCapability::PRIVACY], true)) {
            $purpose = CustomerCapability::MANAGE;
        }
        $email = mb_strtolower($request->string('email'));
        try {
            // Both budgets apply before lookup, including requests for unknown addresses.
            $allowed = CustomerCapabilityRecovery::allow($request->ip(), $slug, $email);
            if ($allowed && $tenant !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                CustomerCapabilityRecovery::request($tenant['id'], $purpose, $subjectId, $email);
            }
        } catch (\Throwable) {
            Logger::error('Customer access recovery storage unavailable');
            return CustomerCapability::protect(Response::html(__('booking.access.unavailable'), 503))->header('Retry-After', '60');
        }
        $displayTenant = $tenant ?? ['slug' => $slug, 'name' => ''];
        Locale::resolveForBooking($displayTenant, $request->header('Accept-Language'));
        return self::recoveryPage($displayTenant, $purpose, $subjectId, true)->status(202);
    }

    private function tenant(string $slug): ?array
    {
        return Database::query('SELECT * FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1', [$slug, 'active'])[0] ?? null;
    }
}
