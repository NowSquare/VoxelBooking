<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Customer data exporter for GDPR Art. 20 (Right to data portability).
 *
 * Generates a machine-readable JSON export of all data associated with
 * a customer: personal details, booking history, consent records, and
 * email communication log.
 *
 * The export is structured for portability — a customer (or data controller)
 * can read exactly what data is held about them and import it elsewhere.
 *
 * This engine does NOT handle authentication or authorization.
 * The calling controller is responsible for verifying the requester's identity.
 */
final class DataExporter
{
    /**
     * Export all data for a customer as a structured array.
     *
     * @param string $customerId Customer ULID
     *
     * @return array{
     *     export_version: string,
     *     exported_at: string,
     *     customer: array,
     *     bookings: array,
     *     consent_records: array,
     *     email_log: array
     * }
     *
     * @throws \RuntimeException if customer not found
     */
    public static function export(string $customerId): array
    {
        $customer = self::loadCustomer($customerId);

        if ($customer === null) {
            throw new \RuntimeException("Customer {$customerId} not found");
        }

        if ((int) ($customer['is_anonymized'] ?? 0) === 1) {
            throw new \RuntimeException("Customer {$customerId} has been anonymized — no PII to export");
        }

        $bookings = self::loadBookings($customerId, $customer['tenant_id']);
        $consentRecords = self::extractConsentRecords($bookings);
        $emailLog = self::loadEmailLog($customerId, $customer['tenant_id']);

        return [
            'export_version' => '1.0',
            'exported_at'    => date('c'),
            'customer'       => self::formatCustomer($customer),
            'bookings'       => self::formatBookings($bookings),
            'consent_records' => $consentRecords,
            'email_log'      => self::formatEmailLog($emailLog),
        ];
    }

    /**
     * Export as JSON string.
     *
     * @param string $customerId Customer ULID
     *
     * @return string JSON-encoded export
     */
    public static function exportJson(string $customerId): string
    {
        $data = self::export($customerId);

        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    // ── Loaders ──

    /**
     * @return array<string, mixed>|null
     */
    private static function loadCustomer(string $customerId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `customers` WHERE `id` = ? LIMIT 1',
            [$customerId]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadBookings(string $customerId, string $tenantId): array
    {
        return Database::query(
            'SELECT * FROM `bookings` WHERE `customer_id` = ? AND `tenant_id` = ? ORDER BY `start_datetime` DESC',
            [$customerId, $tenantId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadEmailLog(string $customerId, string $tenantId): array
    {
        return Database::query(
            'SELECT el.* FROM `email_log` el
             INNER JOIN `bookings` b ON el.`booking_id` = b.`id` AND el.`tenant_id` = b.`tenant_id`
             WHERE b.`customer_id` = ? AND b.`tenant_id` = ?
             ORDER BY el.`sent_at` DESC',
            [$customerId, $tenantId]
        );
    }

    // ── Formatters (strip internal columns, present clean data) ──

    /**
     * @return array<string, mixed>
     */
    private static function formatCustomer(array $customer): array
    {
        return [
            'name'       => $customer['name'],
            'email'      => $customer['email'],
            'phone'      => $customer['phone'],
            'notes'      => $customer['notes'],
            'created_at' => $customer['created_at'],
            'updated_at' => $customer['updated_at'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function formatBookings(array $bookings): array
    {
        return array_map(fn(array $b) => [
            'booking_pattern'    => $b['booking_pattern'],
            'contact' => ['name' => $b['customer_name_snapshot'] ?? null, 'email' => $b['customer_email_snapshot'] ?? null, 'phone' => $b['customer_phone_snapshot'] ?? null],
            'start_datetime'     => $b['start_datetime'],
            'end_datetime'       => $b['end_datetime'],
            'party_size'         => $b['party_size'],
            'status'             => $b['status'],
            'notes'              => $b['notes'],
            'custom_field_data'  => $b['custom_field_data'] !== null
                ? json_decode($b['custom_field_data'], true)
                : null,
            'customer_timezone'  => $b['customer_timezone'],
            'source'             => $b['source'],
            'created_at'         => $b['created_at'],
        ], $bookings);
    }

    /**
     * Extract consent records from bookings (never anonymized).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractConsentRecords(array $bookings): array
    {
        $records = [];

        foreach ($bookings as $b) {
            if ($b['consent_given_at'] !== null) {
                $records[] = [
                    'booking_start'      => $b['start_datetime'],
                    'consent_given_at'   => $b['consent_given_at'],
                    'consent_text_shown' => $b['consent_text_shown'],
                    'source'             => $b['source'],
                ];
            }
        }

        return $records;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function formatEmailLog(array $emails): array
    {
        return array_map(fn(array $e) => [
            'type'    => $e['type'],
            'subject' => $e['subject'],
            'status'  => $e['status'],
            'sent_at' => $e['sent_at'],
        ], $emails);
    }
}
