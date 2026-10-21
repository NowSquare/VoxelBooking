<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Agent\ActorContext;
use App\Engine\Database;
use App\Engine\Ulid;

/**
 * A private appointment business in the disposable database.
 *
 * Opening hours run 09:00 to 17:00 every day in UTC, with an owner account
 * that resolves through the real principal resolver. Services default to
 * `requires_staff = 1`, as every service created in the admin does.
 * Deleting the business cascades to everything created here.
 */
final class TimeslotBusiness
{
    public readonly string $id;
    public readonly string $slug;
    public readonly string $ownerId;

    private function __construct(string $id, string $slug, string $ownerId)
    {
        $this->id = $id;
        $this->slug = $slug;
        $this->ownerId = $ownerId;
    }

    public static function create(array $columns = []): self
    {
        TestEnvironment::assertDatabase();
        $id = Ulid::generate();
        $slug = 'appointments-' . strtolower(substr($id, -10));
        $row = $columns + [
            'id' => $id,
            'slug' => $slug,
            'name' => 'Example Studio',
            'email' => $slug . '@example.test',
            'booking_pattern' => 'timeslot',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'EUR',
            'requires_consent' => 0,
            'min_advance_hours' => 0,
            'max_advance_days' => 60,
            'slot_duration_minutes' => 30,
            'buffer_minutes' => 0,
        ];
        $names = '`' . implode('`, `', array_keys($row)) . '`';
        $marks = implode(', ', array_fill(0, count($row), '?'));
        Database::execute("INSERT INTO `tenants` ({$names}) VALUES ({$marks})", array_values($row));

        for ($day = 0; $day <= 6; $day++) {
            Database::execute(
                "INSERT INTO `availability` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                 VALUES (?, ?, ?, '09:00', '17:00', 1)",
                [Ulid::generate(), $id, $day]
            );
        }

        $ownerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`)
             VALUES (?, ?, 'Example Owner', ?, 'unused', 'owner', 1)",
            [$ownerId, $id, 'owner-' . strtolower(substr($ownerId, -10)) . '@example.test']
        );

        return new self($id, $slug, $ownerId);
    }

    public function delete(): void
    {
        Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [$this->id]);
    }

    /** @return array<string, mixed> the current tenant row */
    public function row(): array
    {
        return Database::query('SELECT * FROM `tenants` WHERE `id` = ?', [$this->id])[0];
    }

    public function owner(): ActorContext
    {
        return new ActorContext('business_user', $this->ownerId, 'business_owner', [$this->id], ['bookings:read', 'bookings:write']);
    }

    public function prospectiveCustomer(): ActorContext
    {
        return new ActorContext('public', Ulid::generate(), 'prospective_customer', [$this->id], ['public:read', 'public:book'], authMode: 'public');
    }

    public function staff(string $name): string
    {
        $id = Ulid::generate();
        Database::execute(
            'INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`) VALUES (?, ?, ?, ?, 1, ?)',
            [$id, $this->id, $name, strtolower($name) . '-' . strtolower(substr($id, -6)) . '@example.test', $name === 'Alex' ? 1 : 2]
        );

        return $id;
    }

    /** @param list<string> $linkedStaff */
    public function service(array $linkedStaff = [], int $minutes = 30): string
    {
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `price`, `is_active`, `sort_order`)
             VALUES (?, ?, 'Consultation', ?, 25.00, 1, 1)",
            [$id, $this->id, $minutes]
        );
        foreach ($linkedStaff as $staffId) {
            Database::execute('INSERT INTO `service_staff` (`service_id`, `staff_id`) VALUES (?, ?)', [$id, $staffId]);
        }

        return $id;
    }

    /** Insert a booking directly, as earlier activity the scenario starts from. */
    public function occupy(?string $staffId, string $start, string $end, string $status, ?string $email = null): string
    {
        $service = Database::query('SELECT `id` FROM `services` WHERE `tenant_id` = ? ORDER BY `id` LIMIT 1', [$this->id])[0]['id'] ?? null;
        $customerId = Ulid::generate();
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Earlier Customer', ?)",
            [$customerId, $this->id, $email ?? 'earlier-' . strtolower(substr($customerId, -8)) . '@example.test']
        );
        $id = Ulid::generate();
        Database::execute(
            "INSERT INTO `bookings` (`id`, `tenant_id`, `customer_id`, `booking_pattern`, `service_id`, `staff_id`, `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, ?, 'timeslot', ?, ?, ?, ?, ?, 'web')",
            [$id, $this->id, $customerId, $service, $staffId, $start, $end, $status]
        );

        return $id;
    }

    /** A date relative to today in the business timezone (UTC). */
    public static function day(int $offset): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify("+{$offset} days")->format('Y-m-d');
    }
}
