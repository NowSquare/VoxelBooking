<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Customer find-or-create logic, shared between public booking API
 * and admin manual booking creation.
 *
 * Deduplicates by (tenant_id, email). Public submissions never overwrite an
 * existing profile; submitted contact details belong to the booking snapshot.
 */
final class CustomerService
{
    /**
     * Find an existing customer by email within a tenant, or create a new one.
     *
     * @param string $tenantId Tenant ULID
     * @param string $name     Customer display name
     * @param string $email    Customer email (unique per tenant)
     * @param string $phone    Customer phone (optional, pass empty string if none)
     *
     * @return string The customer ULID (existing or newly created)
     */
    public static function findOrCreate(
        string $tenantId,
        string $name,
        string $email,
        string $phone,
    ): string {
        $existing = Database::query(
            'SELECT `id` FROM `customers` WHERE `tenant_id` = ? AND `email` = ? LIMIT 1',
            [$tenantId, $email]
        );

        if (!empty($existing)) {
            return $existing[0]['id'];
        }

        $customerId = Ulid::generate();
        Database::execute(
            'INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`, `phone`) VALUES (?, ?, ?, ?, ?)',
            [$customerId, $tenantId, $name, $email, $phone ?: null]
        );

        return $customerId;
    }
}
