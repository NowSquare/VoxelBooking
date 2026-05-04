<?php

declare(strict_types=1);

/**
 * Demo database seeder.
 *
 * Creates a SQLite database at storage/demo/demo.db with fictional data
 * suitable for demonstrating VoxelBooking features.
 *
 * Usage: php demo-seed.php
 *
 * IMPORTANT: All data is fictional. No real PII.
 * Per .ai/23 §6: demo database must contain only fictional customer data.
 *
 * Schema parity: every CREATE TABLE here must mirror the corresponding
 * base migration in app/Migrations/. When a migration changes, this file
 * must be updated to match. See .ai/2001 §Baseline seed parity.
 */

$basePath = __DIR__;
$demoDir  = $basePath . '/storage/demo';
$demoDb   = $demoDir . '/demo.db';

// Ensure directory exists
if (!is_dir($demoDir)) {
    mkdir($demoDir, 0755, true);
}

// Remove existing database
if (file_exists($demoDb)) {
    unlink($demoDb);
}

$pdo = new PDO('sqlite:' . $demoDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ══════════════════════════════════════════════════════════════════════
// Schema — mirrors app/Migrations/* (SQLite dialect)
// ══════════════════════════════════════════════════════════════════════

// 001: settings
$pdo->exec("
CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    `key` TEXT NOT NULL UNIQUE,
    value TEXT,
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 002: operators
$pdo->exec("
CREATE TABLE operators (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 002b: auth_emails — global email uniqueness for passwordless login
$pdo->exec("
CREATE TABLE auth_emails (
    email TEXT PRIMARY KEY,
    user_type TEXT NOT NULL,
    user_id TEXT NOT NULL
)
");

// 002c: login_tokens — OTP codes and magic-link tokens
$pdo->exec("
CREATE TABLE login_tokens (
    id TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    type TEXT NOT NULL,
    token_hash TEXT NOT NULL,
    remember_me INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    used_at TEXT DEFAULT NULL,
    ip_address TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
");

// 003: tenants — mirrors 003_create_tenants.php
$pdo->exec("
CREATE TABLE tenants (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    timezone TEXT NOT NULL DEFAULT 'UTC',
    booking_pattern TEXT NOT NULL DEFAULT 'timeslot',
    locale TEXT NOT NULL DEFAULT 'en',
    locale_override TEXT DEFAULT NULL,
    week_start INTEGER DEFAULT NULL,
    time_format TEXT DEFAULT NULL,
    date_format TEXT DEFAULT NULL,
    number_format TEXT DEFAULT NULL,
    currency TEXT NOT NULL DEFAULT 'EUR',
    brand_color TEXT NOT NULL DEFAULT '#2563EB',
    brand_color_text TEXT NOT NULL DEFAULT '#FFFFFF',
    logo_path TEXT DEFAULT NULL,
    cover_image_path TEXT DEFAULT NULL,
    booking_page_heading TEXT DEFAULT NULL,
    booking_page_description TEXT DEFAULT NULL,
    confirmation_message TEXT DEFAULT NULL,
    cancellation_policy TEXT DEFAULT NULL,
    allow_cancellation INTEGER NOT NULL DEFAULT 1,
    cancellation_hours_before INTEGER NOT NULL DEFAULT 24,
    allow_rescheduling INTEGER NOT NULL DEFAULT 1,
    rescheduling_hours_before INTEGER NOT NULL DEFAULT 24,
    min_advance_hours INTEGER NOT NULL DEFAULT 1,
    max_advance_days INTEGER NOT NULL DEFAULT 90,
    slot_duration_minutes INTEGER NOT NULL DEFAULT 30,
    buffer_minutes INTEGER NOT NULL DEFAULT 0,
    max_bookings_per_customer_per_day INTEGER NOT NULL DEFAULT 3,
    booking_requires_approval INTEGER NOT NULL DEFAULT 0,
    require_phone INTEGER NOT NULL DEFAULT 0,
    custom_fields TEXT DEFAULT NULL,
    notification_email TEXT DEFAULT NULL,
    notify_on_booking INTEGER NOT NULL DEFAULT 1,
    notify_on_cancellation INTEGER NOT NULL DEFAULT 1,
    send_reminders INTEGER NOT NULL DEFAULT 1,
    reminder_hours_before INTEGER NOT NULL DEFAULT 24,
    requires_consent INTEGER NOT NULL DEFAULT 1,
    privacy_policy_url TEXT DEFAULT NULL,
    consent_text TEXT DEFAULT NULL,
    data_retention_months INTEGER NOT NULL DEFAULT 24,
    allowed_embed_domains TEXT DEFAULT NULL,
    embed_button_position TEXT NOT NULL DEFAULT 'bottom-right',
    embed_button_label TEXT NOT NULL DEFAULT 'Book Now',
    status TEXT NOT NULL DEFAULT 'active',
    meta TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 004: audit_log
$pdo->exec("
CREATE TABLE audit_log (
    id TEXT PRIMARY KEY,
    tenant_id TEXT DEFAULT NULL,
    actor_type TEXT NOT NULL,
    actor_id TEXT DEFAULT NULL,
    action TEXT NOT NULL,
    entity_type TEXT DEFAULT NULL,
    entity_id TEXT DEFAULT NULL,
    details TEXT DEFAULT '{}',
    ip_address TEXT DEFAULT NULL,
    request_id TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)
");

// 005: services — mirrors 005_create_services.php
$pdo->exec("
CREATE TABLE services (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    preparation_text TEXT DEFAULT NULL,
    duration_minutes INTEGER NOT NULL DEFAULT 30,
    duration_options TEXT DEFAULT NULL,
    price REAL DEFAULT NULL,
    price_label TEXT DEFAULT NULL,
    category TEXT DEFAULT NULL,
    cover_image_path TEXT DEFAULT NULL,
    color TEXT DEFAULT NULL,
    max_per_day INTEGER DEFAULT NULL,
    requires_staff INTEGER NOT NULL DEFAULT 1,
    staff_assignment_strategy TEXT NOT NULL DEFAULT 'first_available',
    is_virtual INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    meta TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 006: staff — mirrors 006_create_staff.php
$pdo->exec("
CREATE TABLE staff (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    title TEXT DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    avatar_path TEXT DEFAULT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    meta TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 007: service_staff
$pdo->exec("
CREATE TABLE service_staff (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id TEXT NOT NULL,
    staff_id TEXT NOT NULL,
    UNIQUE(service_id, staff_id)
)
");

// 008: availability — mirrors 008_create_availability.php
$pdo->exec("
CREATE TABLE availability (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    staff_id TEXT DEFAULT NULL,
    day_of_week INTEGER NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    is_available INTEGER NOT NULL DEFAULT 1
)
");

// 009: blocked_dates — mirrors 009_create_blocked_dates.php
$pdo->exec("
CREATE TABLE blocked_dates (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    staff_id TEXT DEFAULT NULL,
    resource_id TEXT DEFAULT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    reason TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)
");

// 023: resources — mirrors 023_create_resources.php + 027_add_resource_checkin_checkout_days.php
$pdo->exec("
CREATE TABLE resources (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    capacity INTEGER NOT NULL DEFAULT 1,
    cover_image_path TEXT DEFAULT NULL,
    amenities TEXT DEFAULT NULL,
    price_per_night REAL DEFAULT NULL,
    min_stay_nights INTEGER NOT NULL DEFAULT 1,
    max_stay_nights INTEGER NOT NULL DEFAULT 30,
    check_in_days TEXT DEFAULT NULL,
    check_out_days TEXT DEFAULT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 024: seasonal_pricing — mirrors 024_create_seasonal_pricing.php
$pdo->exec("
CREATE TABLE seasonal_pricing (
    id TEXT PRIMARY KEY,
    resource_id TEXT NOT NULL,
    tenant_id TEXT NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    price_per_night REAL NOT NULL,
    label TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)
");

// 025: capacity_slots — mirrors 025_create_capacity_slots.php
$pdo->exec("
CREATE TABLE capacity_slots (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    day_of_week INTEGER NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    max_capacity INTEGER NOT NULL DEFAULT 20,
    min_party_size INTEGER NOT NULL DEFAULT 1,
    max_party_size INTEGER NOT NULL DEFAULT 8,
    label TEXT DEFAULT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 028: business_applications — mirrors 028_create_business_applications.php
$pdo->exec("
CREATE TABLE business_applications (
    id TEXT PRIMARY KEY,
    business_name TEXT NOT NULL,
    contact_name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    website TEXT DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    admin_notes TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    reviewed_at TEXT DEFAULT NULL,
    reviewed_by TEXT DEFAULT NULL
)
");

// 026: events
$pdo->exec("
CREATE TABLE events (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    location TEXT DEFAULT NULL,
    price TEXT DEFAULT NULL,
    max_participants INTEGER NOT NULL DEFAULT 20,
    min_spot_count INTEGER NOT NULL DEFAULT 1,
    max_spot_count INTEGER DEFAULT NULL,
    start_datetime TEXT NOT NULL,
    end_datetime TEXT NOT NULL,
    is_recurring INTEGER NOT NULL DEFAULT 0,
    rrule TEXT DEFAULT NULL,
    exception_dates TEXT DEFAULT NULL,
    allow_waitlist INTEGER NOT NULL DEFAULT 0,
    waitlist_max INTEGER NOT NULL DEFAULT 0,
    cover_image_path TEXT DEFAULT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 010: api_keys
$pdo->exec("
CREATE TABLE api_keys (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    key_hash TEXT NOT NULL UNIQUE,
    key_prefix TEXT NOT NULL,
    scopes TEXT NOT NULL DEFAULT '[]',
    role TEXT NOT NULL DEFAULT 'viewer',
    is_active INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT DEFAULT NULL,
    expires_at TEXT DEFAULT NULL,
    created_by TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 011: business_users — mirrors 011_create_business_users.php
$pdo->exec("
CREATE TABLE business_users (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'manager',
    is_active INTEGER NOT NULL DEFAULT 1,
    force_password_change INTEGER NOT NULL DEFAULT 0,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 018: rate_limits — mirrors 018_create_rate_limits.php
$pdo->exec("
CREATE TABLE rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    endpoint_group TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
");
$pdo->exec("CREATE INDEX rate_limits_lookup ON rate_limits (ip, endpoint_group, created_at)");

// 019: customers — mirrors 019_create_customers.php
$pdo->exec("
CREATE TABLE customers (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    profile_data TEXT DEFAULT NULL,
    booking_count INTEGER NOT NULL DEFAULT 0,
    last_booking_at TEXT DEFAULT NULL,
    is_anonymized INTEGER NOT NULL DEFAULT 0,
    anonymized_at TEXT DEFAULT NULL,
    deletion_requested_at TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 020: bookings — mirrors 020_create_bookings.php
$pdo->exec("
CREATE TABLE bookings (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    booking_pattern TEXT NOT NULL DEFAULT 'timeslot',
    service_id TEXT DEFAULT NULL,
    staff_id TEXT DEFAULT NULL,
    resource_id TEXT DEFAULT NULL,
    event_id TEXT DEFAULT NULL,
    customer_id TEXT NOT NULL,
    start_datetime TEXT NOT NULL,
    end_datetime TEXT NOT NULL,
    party_size INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'confirmed',
    rescheduled_to_id TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    internal_notes TEXT DEFAULT NULL,
    custom_field_data TEXT DEFAULT NULL,
    consent_given_at TEXT DEFAULT NULL,
    consent_text_shown TEXT DEFAULT NULL,
    customer_timezone TEXT DEFAULT NULL,
    confirmation_sent_at TEXT DEFAULT NULL,
    reminder_sent_at TEXT DEFAULT NULL,
    cancelled_at TEXT DEFAULT NULL,
    cancellation_reason TEXT DEFAULT NULL,
    source TEXT NOT NULL DEFAULT 'web',
    meta TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)
");

// 021: email_log — mirrors 021_create_email_log.php
$pdo->exec("
CREATE TABLE email_log (
    id TEXT PRIMARY KEY,
    tenant_id TEXT DEFAULT NULL,
    booking_id TEXT DEFAULT NULL,
    type TEXT NOT NULL,
    to_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'sent',
    error TEXT DEFAULT NULL,
    sent_at TEXT DEFAULT (datetime('now'))
)
");

// 022: tenant_email_templates — mirrors 022_create_tenant_email_templates.php
$pdo->exec("
CREATE TABLE tenant_email_templates (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    type TEXT NOT NULL,
    subject TEXT DEFAULT NULL,
    heading TEXT DEFAULT NULL,
    body_intro TEXT DEFAULT NULL,
    body_outro TEXT DEFAULT NULL,
    cta_label TEXT DEFAULT NULL,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE(tenant_id, type)
)
");

// ══════════════════════════════════════════════════════════════════════
// Seed Data
// ══════════════════════════════════════════════════════════════════════

$today = new DateTime();
// Settings
$settings = [
    ['installed_at', date('Y-m-d H:i:s')],
    ['db_version', '28'],
    ['app_name', 'VoxelBooking Demo'],
    ['timezone', 'Europe/Amsterdam'],
    ['locale', 'en'],
    ['cron_token', bin2hex(random_bytes(32))],
    ['cron_last_run', date('Y-m-d H:i:s', strtotime('-12 minutes'))],
    ['enable_applications', '1'],
];

$stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)");
foreach ($settings as [$k, $v]) {
    $stmt->execute([$k, $v]);
}

// Operator (password: welcome3210)
$operatorId = '01JDEMO0001OPERATOR001';
$pdo->prepare("INSERT INTO operators (id, name, email, password_hash) VALUES (?, ?, ?, ?)")
    ->execute([$operatorId, 'Demo Admin', 'demo@voxelbooking.com', password_hash('welcome3210', PASSWORD_BCRYPT)]);

// Register operator in auth_emails for passwordless login
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'operator', ?)")
    ->execute(['demo@voxelbooking.com', $operatorId]);

// Tenant
$tenantId = '01JDEMO0001TENANT00001';
$pdo->prepare("
    INSERT INTO tenants (id, name, slug, email, status, timezone, locale, currency,
                         date_format, number_format, time_format, week_start,
                         brand_color, booking_pattern,
                         require_phone, requires_consent, consent_text, privacy_policy_url,
                         slot_duration_minutes, buffer_minutes, min_advance_hours, max_advance_days)
    VALUES (?, ?, ?, ?, 'active', 'Europe/Amsterdam', 'en', 'EUR',
            'd-m-Y', 'period', '24h', 1,
            '#2563EB', 'timeslot',
            0, 1, 'I agree to the processing of my personal data for this booking.', '#',
            30, 0, 1, 90)
")->execute([$tenantId, 'Demo Studio', 'demo', 'hello@demostudio.example']);

// Services
$services = [
    ['01JDEMO0001SERVICE0001', 'Consultation', 'An introductory consultation to discuss your needs and goals.', 'Please bring any relevant documents or previous records.', 60, null, 75.00, '#2563EB'],
    ['01JDEMO0001SERVICE0002', 'Standard Session', 'A standard 45-minute session with your chosen professional.', null, 45, null, 55.00, '#7C3AED'],
    ['01JDEMO0001SERVICE0003', 'Extended Session', 'A deep-dive session for complex topics or follow-up work.', null, 90, '[60, 90, 120]', 120.00, '#059669'],
];

$stmt = $pdo->prepare("INSERT INTO services (id, tenant_id, name, description, preparation_text, duration_minutes, duration_options, price, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
foreach ($services as $i => [$id, $name, $desc, $prep, $dur, $durOpts, $price, $color]) {
    $stmt->execute([$id, $tenantId, $name, $desc, $prep, $dur, $durOpts, $price, $color, $i]);
}

// Staff (rich: email, title, bio)
$staffMembers = [
    ['01JDEMO0001STAFF000001', 'Alice Example', 'Senior Consultant', 'Over 10 years of experience in strategic consulting and client relationship management.'],
    ['01JDEMO0001STAFF000002', 'Bob Demoson', 'Specialist', 'Certified specialist with a focus on technical problem-solving and hands-on sessions.'],
    ['01JDEMO0001STAFF000003', 'Charlie Fixture', null, 'Flexible team member available for all service types and scheduling needs.'],
];

$stmt = $pdo->prepare("INSERT INTO staff (id, tenant_id, name, email, title, bio, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
foreach ($staffMembers as $i => [$id, $name, $title, $bio]) {
    $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
    $stmt->execute([$id, $tenantId, $name, $email, $title, $bio, $i]);
}

// Service-staff links (all staff can do all services)
$stmt = $pdo->prepare("INSERT INTO service_staff (service_id, staff_id) VALUES (?, ?)");
foreach ($services as [$svcId]) {
    foreach ($staffMembers as [$staffId]) {
        $stmt->execute([$svcId, $staffId]);
    }
}

// Availability (Mon-Fri 9:00-17:00 — is_available, not is_active)
// day_of_week: 0=Mon, 6=Sun (see 008_create_availability.php)
$stmt = $pdo->prepare("INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
$availId = 1;

// Tenant-level defaults (staff_id NULL)
for ($day = 0; $day <= 4; $day++) {
    $stmt->execute([
        sprintf('01JDEMO0001TAVAIL%05d', $day),
        $tenantId,
        null,
        $day,
        '09:00',
        '17:00'
    ]);
}

// Staff-level availability
foreach ($staffMembers as [$staffId]) {
    for ($day = 0; $day <= 4; $day++) {
        $stmt->execute([
            sprintf('01JDEMO0001AVAIL%06d', $availId++),
            $tenantId,
            $staffId,
            $day,
            '09:00',
            '17:00'
        ]);
    }
}

// Customers (8 for a realistic CRM view)
$customers = [
    ['01JDEMO0001CUST0000001', 'Emma Johnson', 'emma.johnson@example.com', '+31 6 0000 0001', 4],
    ['01JDEMO0001CUST0000002', 'James Smith', 'james.smith@example.com', '+31 6 0000 0002', 3],
    ['01JDEMO0001CUST0000003', 'Sophie Brown', 'sophie.brown@example.com', null, 2],
    ['01JDEMO0001CUST0000004', 'Noah Williams', 'noah.williams@example.com', '+31 6 0000 0004', 2],
    ['01JDEMO0001CUST0000005', 'Mia Davis', 'mia.davis@example.com', '+31 6 0000 0005', 1],
    ['01JDEMO0001CUST0000006', 'Lucas van Berg', 'lucas.vanberg@example.com', '+31 6 0000 0006', 1],
    ['01JDEMO0001CUST0000007', 'Olivia de Vries', 'olivia.devries@example.com', '+31 6 0000 0007', 1],
    ['01JDEMO0001CUST0000008', 'Ethan Bakker', 'ethan.bakker@example.com', null, 0],
];

$stmt = $pdo->prepare("INSERT INTO customers (id, tenant_id, name, email, phone, booking_count) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($customers as [$id, $name, $email, $phone, $bc]) {
    $stmt->execute([$id, $tenantId, $name, $email, $phone, $bc]);
}

// Bookings (timeslot) — 14 total: 3 today, 5 upcoming, 6 past
$bookings = [
    // Today's bookings (fills "Today's Schedule" dashboard)
    ['01JDEMO0001BOOK0000001', $customers[0][0], $services[0][0], $staffMembers[0][0], '+0 days', '10:00', '11:00', 'confirmed'],
    ['01JDEMO0001BOOK0000002', $customers[1][0], $services[1][0], $staffMembers[1][0], '+0 days', '11:00', '11:45', 'confirmed'],
    ['01JDEMO0001BOOK0000003', $customers[3][0], $services[0][0], $staffMembers[2][0], '+0 days', '14:00', '15:00', 'confirmed'],
    // Upcoming
    ['01JDEMO0001BOOK0000004', $customers[2][0], $services[2][0], $staffMembers[0][0], '+1 day',  '09:00', '10:30', 'confirmed'],
    ['01JDEMO0001BOOK0000005', $customers[4][0], $services[0][0], $staffMembers[1][0], '+1 day',  '13:00', '14:00', 'confirmed'],
    ['01JDEMO0001BOOK0000006', $customers[5][0], $services[1][0], $staffMembers[0][0], '+2 days', '10:00', '10:45', 'confirmed'],
    ['01JDEMO0001BOOK0000007', $customers[6][0], $services[2][0], $staffMembers[2][0], '+3 days', '15:00', '16:30', 'confirmed'],
    ['01JDEMO0001BOOK0000008', $customers[0][0], $services[1][0], $staffMembers[1][0], '+5 days', '09:00', '09:45', 'confirmed'],
    // Past (history)
    ['01JDEMO0001BOOK0000009', $customers[0][0], $services[0][0], $staffMembers[0][0], '-1 day',  '10:00', '11:00', 'completed'],
    ['01JDEMO0001BOOK0000010', $customers[1][0], $services[1][0], $staffMembers[1][0], '-2 days', '14:00', '14:45', 'completed'],
    ['01JDEMO0001BOOK0000011', $customers[0][0], $services[0][0], $staffMembers[2][0], '-3 days', '11:00', '12:00', 'completed'],
    ['01JDEMO0001BOOK0000012', $customers[3][0], $services[2][0], $staffMembers[0][0], '-5 days', '09:00', '10:30', 'completed'],
    ['01JDEMO0001BOOK0000013', $customers[1][0], $services[0][0], $staffMembers[1][0], '-7 days', '15:00', '16:00', 'cancelled'],
    ['01JDEMO0001BOOK0000014', $customers[2][0], $services[1][0], $staffMembers[2][0], '-10 days','10:00', '10:45', 'completed'],
];

$stmt = $pdo->prepare("
    INSERT INTO bookings (id, tenant_id, booking_pattern, customer_id, service_id, staff_id,
                          start_datetime, end_datetime, status, source,
                          customer_timezone, consent_given_at, consent_text_shown)
    VALUES (?, ?, 'timeslot', ?, ?, ?, ?, ?, ?, 'web',
            'Europe/Amsterdam', datetime('now'), 'I agree to the processing of my personal data for this booking.')
");
foreach ($bookings as [$id, $custId, $svcId, $staffId, $dateOffset, $start, $end, $status]) {
    $date = (clone $today)->modify($dateOffset)->format('Y-m-d');
    $stmt->execute([$id, $tenantId, $custId, $svcId, $staffId, "{$date} {$start}:00", "{$date} {$end}:00", $status]);
}


// Audit log sample entries — diverse actions across tenants
$stmt = $pdo->prepare("INSERT INTO audit_log (id, tenant_id, actor_type, actor_id, action, entity_type, entity_id, details, ip_address, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$logEntries = [
    // System / operator login
    ['01JDEMO0001AUDIT000001', null,      'operator', $operatorId, 'auth.login',             'operator', $operatorId, '{}', '93.184.216.34', 'demo-req-001'],
    ['01JDEMO0001AUDIT000002', null,      'operator', $operatorId, 'auth.password_changed',  'operator', $operatorId, '{}', '93.184.216.34', 'demo-req-002'],
    // Tenant config
    ['01JDEMO0001AUDIT000003', $tenantId, 'operator', $operatorId, 'settings.updated',       'settings', null, '{"key":"site_name","old":"VoxelBooking","new":"VoxelBooking Demo"}', '93.184.216.34', 'demo-req-003'],
    ['01JDEMO0001AUDIT000004', $tenantId, 'operator', $operatorId, 'tenant.updated',         'tenant', $tenantId, '{"field":"brand_color","old":"#000","new":"#2563EB"}', '93.184.216.34', 'demo-req-004'],
    // Bookings
    ['01JDEMO0001AUDIT000005', $tenantId, 'system',   null,        'booking.created',        'booking', '01JDEMO0001BOOK0000001', '{"source":"web"}', '198.51.100.12', 'demo-req-005'],
    ['01JDEMO0001AUDIT000006', $tenantId, 'system',   null,        'booking.created',        'booking', '01JDEMO0001BOOK0000002', '{"source":"web"}', '198.51.100.45', 'demo-req-006'],
    ['01JDEMO0001AUDIT000007', $tenantId, 'operator', $operatorId, 'booking.status_changed', 'booking', '01JDEMO0001BOOK0000004', '{"old":"confirmed","new":"completed"}', '93.184.216.34', 'demo-req-007'],
    // Hotel Marina
    ['01JDEMO0001AUDIT000008', '01JDEMO0002TENANT00001', 'system', null, 'booking.created',   'booking', '01JDEMO0002BOOK0000001', '{"source":"web"}', '203.0.113.20', 'demo-req-008'],
    ['01JDEMO0001AUDIT000009', '01JDEMO0002TENANT00001', 'system', null, 'booking.cancelled', 'booking', '01JDEMO0002BOOK0000005', '{"reason":"guest_request"}', '203.0.113.20', 'demo-req-009'],
    // Trattoria Roma
    ['01JDEMO0001AUDIT000010', '01JDEMO0003TENANT00001', 'system', null, 'booking.created', 'booking', '01JDEMO0003BOOK0000001', '{"source":"web","party_size":4}', '198.51.100.78', 'demo-req-010'],
    // Customer data
    ['01JDEMO0001AUDIT000011', $tenantId, 'operator', $operatorId, 'customer.updated',       'customer', '01JDEMO0001CUST0000001', '{"field":"phone"}', '93.184.216.34', 'demo-req-011'],
    ['01JDEMO0001AUDIT000012', $tenantId, 'system',   null,        'privacy.request_received','customer', '01JDEMO0001CUST0000003', '{}', '198.51.100.99', 'demo-req-012'],
    // Workshop Studio
    ['01JDEMO0001AUDIT000013', '01JDEMO0004TENANT00001', 'system', null, 'booking.created', 'booking', '01JDEMO0004BOOK0000001', '{"source":"web"}', '203.0.113.55', 'demo-req-013'],
    // Retention job
    ['01JDEMO0001AUDIT000014', null, 'system', null, 'retention.executed', 'system', null, '{"anonymized":0,"email_log_deleted":0,"audit_log_deleted":0}', '127.0.0.1', 'demo-req-014'],
    // Export
    ['01JDEMO0001AUDIT000015', $tenantId, 'operator', $operatorId, 'data.export_generated',  'tenant', $tenantId, '{"type":"bookings_csv"}', '93.184.216.34', 'demo-req-015'],
];
foreach ($logEntries as $log) {
    $stmt->execute($log);
}

// Email log sample entries — transactional emails across tenants
$emailStmt = $pdo->prepare("INSERT INTO email_log (id, tenant_id, booking_id, type, to_email, subject, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$emailEntries = [
    ['01JDEMO0001ELOG0000001', $tenantId, '01JDEMO0001BOOK0000001', 'confirmation', 'emma.johnson@example.com', 'Your appointment at Demo Studio is confirmed', 'sent', date('Y-m-d H:i:s', strtotime('-1 day'))],
    ['01JDEMO0001ELOG0000002', $tenantId, '01JDEMO0001BOOK0000002', 'confirmation', 'james.smith@example.com',  'Your appointment at Demo Studio is confirmed', 'sent', date('Y-m-d H:i:s', strtotime('-1 day'))],
    ['01JDEMO0001ELOG0000003', $tenantId, '01JDEMO0001BOOK0000001', 'reminder',     'emma.johnson@example.com', 'Reminder: Your appointment tomorrow',          'sent', date('Y-m-d H:i:s', strtotime('-2 hours'))],
    ['01JDEMO0001ELOG0000004', $tenantId, '01JDEMO0001BOOK0000004', 'confirmation', 'emma.johnson@example.com', 'Your appointment at Demo Studio is confirmed', 'sent', date('Y-m-d H:i:s', strtotime('-3 days'))],
    ['01JDEMO0001ELOG0000005', '01JDEMO0002TENANT00001', '01JDEMO0002BOOK0000001', 'confirmation', 'laura.rossi@example.com', 'Your reservation at Hotel Marina is confirmed', 'sent', date('Y-m-d H:i:s', strtotime('-2 days'))],
    ['01JDEMO0001ELOG0000006', '01JDEMO0002TENANT00001', '01JDEMO0002BOOK0000005', 'cancellation', 'marco.bianchi@example.com', 'Your reservation has been cancelled', 'sent', date('Y-m-d H:i:s', strtotime('-1 day'))],
    ['01JDEMO0001ELOG0000007', '01JDEMO0003TENANT00001', '01JDEMO0003BOOK0000001', 'confirmation', 'antonio.verdi@example.com', 'Your table at Trattoria Roma is reserved', 'sent', date('Y-m-d H:i:s', strtotime('-12 hours'))],
    ['01JDEMO0001ELOG0000008', '01JDEMO0004TENANT00001', '01JDEMO0004BOOK0000001', 'confirmation', 'hannah.weber@example.com', 'You are registered for Woodworking 101', 'sent', date('Y-m-d H:i:s', strtotime('-4 days'))],
];
foreach ($emailEntries as $e) {
    $emailStmt->execute($e);
}

// Deletion request — seed one customer with a pending privacy request
// Sophie Brown (tenant 1) has requested deletion, visible in the deletion queue
$pdo->prepare("UPDATE customers SET deletion_requested_at = datetime('now', '-2 days') WHERE id = ?")->execute(['01JDEMO0001CUST0000003']);

// Tenant email templates (one customized, rest use system defaults)
$stmt = $pdo->prepare("
    INSERT INTO tenant_email_templates (id, tenant_id, type, subject, heading, body_intro, body_outro, cta_label, is_enabled)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    '01JDEMO0001EMAILT000001', $tenantId, 'confirmation',
    'Your appointment at {business_name} is confirmed',
    'See you soon!',
    'Hi {customer_name}, your booking is confirmed. Here are the details:',
    'We look forward to seeing you. If you need to make changes, use the links below.',
    'Add to Calendar',
    1,
]);

// ══════════════════════════════════════════════════════════════════════
// Demo Studio expansion — custom field, cancellation policy, owner
// ══════════════════════════════════════════════════════════════════════

$pdo->prepare("UPDATE tenants SET
    custom_fields = ?,
    cancellation_policy = ?,
    confirmation_message = ?
WHERE id = ?")->execute([
    json_encode([
        ['name' => 'allergies', 'type' => 'text', 'label' => 'Allergies or special requirements', 'required' => false],
    ]),
    'Cancellations must be made at least 24 hours in advance. Late cancellations may be charged the full service fee.',
    'We look forward to seeing you!',
    $tenantId,
]);

// Business user: Demo Studio owner
$demoOwnerId = '01JDEMO0001BUSER000001';
$pdo->prepare("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, 'owner')")
    ->execute([$demoOwnerId, $tenantId, 'Demo Owner', 'owner@demo-studio.test', password_hash('welcome3210', PASSWORD_BCRYPT)]);
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'business_user', ?)")
    ->execute(['owner@demo-studio.test', $demoOwnerId]);

// Demo Studio manager (demonstrates multi-user per tenant)
$demoManagerId = '01JDEMO0001BUSER000002';
$pdo->prepare("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, 'manager')")
    ->execute([$demoManagerId, $tenantId, 'Dana Manager', 'manager@demo-studio.test', password_hash('welcome3210', PASSWORD_BCRYPT)]);
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'business_user', ?)")
    ->execute(['manager@demo-studio.test', $demoManagerId]);

// ======================================================================
// Tenant 2: Hotel Marina (resource pattern)
// ======================================================================

$hotelId = '01JDEMO0002TENANT00001';
$pdo->prepare("
    INSERT INTO tenants (id, name, slug, email, status, timezone, locale, currency,
                         date_format, number_format, time_format, week_start,
                         brand_color, booking_pattern,
                         require_phone, requires_consent, consent_text, cancellation_policy, confirmation_message)
    VALUES (?, 'Hotel Marina', 'hotel-marina', 'info@hotelmarina.example', 'active',
            'Europe/Rome', 'en', 'EUR',
            'd-m-Y', 'period', '24h', 1,
            '#0EA5E9', 'resource',
            1, 1, 'I consent to the processing of my personal data for this reservation.',
            'Free cancellation up to 48 hours before check-in.',
            'Your room is reserved. We look forward to welcoming you!')
")->execute([$hotelId]);


// Hotel Marina — resources (rooms)
$hotelResources = [
    ['01JDEMO0002RES00000001', 'Sea View Suite', 'Spacious suite with panoramic sea view, private balcony, and marble bathroom.', 2, 185.00, 2, 14],
    ['01JDEMO0002RES00000002', 'Garden Room', 'Quiet room overlooking the Mediterranean garden with private patio.', 2, 120.00, 1, 30],
    ['01JDEMO0002RES00000003', 'Family Apartment', 'Two-bedroom apartment with kitchen, living area, and terrace.', 5, 250.00, 3, 21],
];
$stmtRes = $pdo->prepare("
    INSERT INTO resources (id, tenant_id, name, description, capacity, price_per_night, min_stay_nights, max_stay_nights, amenities, sort_order, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
");
$stmtRes->execute([$hotelResources[0][0], $hotelId, $hotelResources[0][1], $hotelResources[0][2], $hotelResources[0][3], $hotelResources[0][4], $hotelResources[0][5], $hotelResources[0][6], json_encode(['Wi-Fi', 'Sea view', 'Balcony', 'Air conditioning', 'Mini-bar']), 1]);
$stmtRes->execute([$hotelResources[1][0], $hotelId, $hotelResources[1][1], $hotelResources[1][2], $hotelResources[1][3], $hotelResources[1][4], $hotelResources[1][5], $hotelResources[1][6], json_encode(['Wi-Fi', 'Garden view', 'Patio', 'Air conditioning']), 2]);
$stmtRes->execute([$hotelResources[2][0], $hotelId, $hotelResources[2][1], $hotelResources[2][2], $hotelResources[2][3], $hotelResources[2][4], $hotelResources[2][5], $hotelResources[2][6], json_encode(['Wi-Fi', 'Kitchen', 'Terrace', 'Air conditioning', 'Washing machine']), 3]);

// Hotel Marina — seasonal pricing (high season for Sea View Suite)
$pdo->prepare("
    INSERT INTO seasonal_pricing (id, resource_id, tenant_id, start_date, end_date, price_per_night, label)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute(['01JDEMO0002SEAS0000001', $hotelResources[0][0], $hotelId, date('Y') . '-07-01', date('Y') . '-08-31', 249.00, 'High Season']);
$pdo->prepare("
    INSERT INTO seasonal_pricing (id, resource_id, tenant_id, start_date, end_date, price_per_night, label)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute(['01JDEMO0002SEAS0000002', $hotelResources[0][0], $hotelId, date('Y') . '-12-20', (date('Y') + 1) . '-01-05', 279.00, 'Holiday Rate']);

// Hotel Marina — blocked date (maintenance)
$pdo->prepare("INSERT INTO blocked_dates (id, tenant_id, resource_id, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute(['01JDEMO0002BLOCK000001', $hotelId, $hotelResources[1][0], date('Y-m', strtotime('+2 months')) . '-10', date('Y-m', strtotime('+2 months')) . '-15', 'Garden renovation']);

// Hotel Marina — customers (7 for screenshot-ready CRM)
$hotelCustomers = [
    ['01JDEMO0002CUST0000001', 'Laura Rossi', 'laura.rossi@example.com', '+39 333 000 0001', 3],
    ['01JDEMO0002CUST0000002', 'Marco Bianchi', 'marco.bianchi@example.com', '+39 333 000 0002', 2],
    ['01JDEMO0002CUST0000003', 'Giulia Ferrara', 'giulia.ferrara@example.com', '+39 333 000 0003', 2],
    ['01JDEMO0002CUST0000004', 'Stefan Müller', 'stefan.mueller@example.com', '+49 170 000 0004', 1],
    ['01JDEMO0002CUST0000005', 'Anna Petersen', 'anna.petersen@example.com', '+45 20 00 00 05', 1],
    ['01JDEMO0002CUST0000006', 'Jean-Pierre Moreau', 'jp.moreau@example.com', '+33 6 00 00 00 06', 1],
    ['01JDEMO0002CUST0000007', 'Yuki Tanaka', 'yuki.tanaka@example.com', '+81 90 0000 0007', 0],
];
$stmt = $pdo->prepare("INSERT INTO customers (id, tenant_id, name, email, phone, booking_count) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($hotelCustomers as [$id, $name, $email, $phone, $bc]) {
    $stmt->execute([$id, $hotelId, $name, $email, $phone, $bc]);
}

// Hotel Marina — bookings (11 total: 1 today check-in, 1 current stay, 3 upcoming, 5 past, 1 extra)
$hotelBookings = [
    // Today's check-in (appears in "Bookings Today" dashboard metric)
    ['01JDEMO0002BOOK0000001', $hotelCustomers[0][0], '+0 days', '+3 days', 'confirmed', 2, $hotelResources[0][0]],
    // Current stay (checked in before today)
    ['01JDEMO0002BOOK0000002', $hotelCustomers[3][0], '-2 days', '+1 day',  'confirmed', 4, $hotelResources[2][0]],
    ['01JDEMO0002BOOK0000003', $hotelCustomers[1][0], '+2 days', '+5 days',  'confirmed', 1, $hotelResources[1][0]],
    ['01JDEMO0002BOOK0000004', $hotelCustomers[4][0], '+5 days', '+8 days',  'confirmed', 2, $hotelResources[0][0]],
    ['01JDEMO0002BOOK0000005', $hotelCustomers[5][0], '+7 days', '+10 days', 'confirmed', 3, $hotelResources[2][0]],
    ['01JDEMO0002BOOK0000006', $hotelCustomers[0][0], '-12 days', '-9 days', 'completed', 2, $hotelResources[0][0]],
    ['01JDEMO0002BOOK0000007', $hotelCustomers[2][0], '-8 days', '-5 days',  'completed', 2, $hotelResources[1][0]],
    ['01JDEMO0002BOOK0000008', $hotelCustomers[1][0], '-6 days', '-3 days',  'completed', 3, $hotelResources[2][0]],
    ['01JDEMO0002BOOK0000009', $hotelCustomers[2][0], '-4 days', '-2 days',  'cancelled', 1, $hotelResources[0][0]],
    ['01JDEMO0002BOOK0000010', $hotelCustomers[0][0], '-15 days','-12 days', 'completed', 2, $hotelResources[1][0]],
    ['01JDEMO0002BOOK0000011', $hotelCustomers[6][0], '-1 day',  '+2 days',  'confirmed', 1, $hotelResources[1][0]],
];
$stmt = $pdo->prepare("
    INSERT INTO bookings (id, tenant_id, booking_pattern, customer_id, resource_id, start_datetime, end_datetime,
                          party_size, status, source, customer_timezone,
                          consent_given_at, consent_text_shown)
    VALUES (?, ?, 'resource', ?, ?, ?, ?, ?, ?, 'web', 'Europe/Rome',
            datetime('now'), 'I consent to the processing of my personal data for this reservation.')
");
foreach ($hotelBookings as [$id, $custId, $checkIn, $checkOut, $status, $partySize, $resourceId]) {
    $startDate = (clone $today)->modify($checkIn)->format('Y-m-d');
    $endDate = (clone $today)->modify($checkOut)->format('Y-m-d');
    $stmt->execute([$id, $hotelId, $custId, $resourceId, "{$startDate} 00:00:00", "{$endDate} 00:00:00", $partySize, $status]);
}

// Hotel Marina — owner
$hotelOwnerId = '01JDEMO0002BUSER000001';
$pdo->prepare("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, 'owner')")
    ->execute([$hotelOwnerId, $hotelId, 'Marina Manager', 'owner@hotel-marina.test', password_hash('welcome3210', PASSWORD_BCRYPT)]);
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'business_user', ?)")
    ->execute(['owner@hotel-marina.test', $hotelOwnerId]);

// Hotel Marina — availability (open every day, 24h — hotels never close)
$stmtAvail = $pdo->prepare("INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, NULL, ?, ?, ?, 1)");
for ($day = 0; $day <= 6; $day++) {
    $stmtAvail->execute([sprintf('01JDEMO0002TAVAIL%05d', $day), $hotelId, $day, '00:00', '23:59']);
}

// ══════════════════════════════════════════════════════════════════════
// Tenant 3: Trattoria Roma (capacity pattern)
// ══════════════════════════════════════════════════════════════════════

$trattoriaId = '01JDEMO0003TENANT00001';
$pdo->prepare("
    INSERT INTO tenants (id, name, slug, email, status, timezone, locale, currency,
                         date_format, number_format, time_format, week_start,
                         brand_color, booking_pattern,
                         require_phone, requires_consent, consent_text, cancellation_policy, confirmation_message,
                         custom_fields)
    VALUES (?, 'Trattoria Roma', 'trattoria-roma', 'info@trattoriaroma.example', 'active',
            'Europe/Rome', 'en', 'EUR',
            'd-m-Y', 'period', '24h', 1,
            '#F97316', 'capacity',
            1, 1, 'I agree to the terms and conditions of this reservation.',
            'Cancellations must be made at least 4 hours before your reservation.',
            'Your table is reserved. Buon appetito!',
            ?)
")->execute([$trattoriaId, json_encode([
    ['name' => 'dietary_requirements', 'type' => 'text', 'label' => 'Dietary requirements', 'required' => false],
    ['name' => 'occasion', 'type' => 'text', 'label' => 'Special occasion (birthday, anniversary, etc.)', 'required' => false],
])]);

// Trattoria Roma — customers (7 for rich CRM)
$trattoriaCustomers = [
    ['01JDEMO0003CUST0000001', 'Antonio Verdi', 'antonio.verdi@example.com', '+39 06 000 0001', 3],
    ['01JDEMO0003CUST0000002', 'Francesca Conti', 'francesca.conti@example.com', '+39 06 000 0002', 3],
    ['01JDEMO0003CUST0000003', 'Roberto Moretti', 'roberto.moretti@example.com', '+39 06 000 0003', 2],
    ['01JDEMO0003CUST0000004', 'Elena Ricci', 'elena.ricci@example.com', '+39 06 000 0004', 2],
    ['01JDEMO0003CUST0000005', 'Luca Romano', 'luca.romano@example.com', '+39 06 000 0005', 1],
    ['01JDEMO0003CUST0000006', 'Maria Colombo', 'maria.colombo@example.com', '+39 06 000 0006', 1],
    ['01JDEMO0003CUST0000007', 'Giovanni Russo', 'giovanni.russo@example.com', '+39 06 000 0007', 0],
];
$stmt = $pdo->prepare("INSERT INTO customers (id, tenant_id, name, email, phone, booking_count) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($trattoriaCustomers as [$id, $name, $email, $phone, $bc]) {
    $stmt->execute([$id, $trattoriaId, $name, $email, $phone, $bc]);
}

// Trattoria Roma — bookings (12 total: 3 tonight, 4 upcoming, 5 past)
$trattoriaBookings = [
    ['01JDEMO0003BOOK0000001', $trattoriaCustomers[0][0], '+0 days', '19:00', '21:00', 'confirmed', 4],
    ['01JDEMO0003BOOK0000002', $trattoriaCustomers[1][0], '+0 days', '19:30', '21:30', 'confirmed', 2],
    ['01JDEMO0003BOOK0000003', $trattoriaCustomers[4][0], '+0 days', '21:00', '22:30', 'confirmed', 6],
    ['01JDEMO0003BOOK0000004', $trattoriaCustomers[2][0], '+1 day',  '19:30', '21:30', 'confirmed', 3],
    ['01JDEMO0003BOOK0000005', $trattoriaCustomers[3][0], '+2 days', '20:00', '22:00', 'confirmed', 2],
    ['01JDEMO0003BOOK0000006', $trattoriaCustomers[0][0], '+3 days', '19:00', '21:00', 'confirmed', 8],
    ['01JDEMO0003BOOK0000007', $trattoriaCustomers[5][0], '+5 days', '19:30', '21:30', 'confirmed', 4],
    ['01JDEMO0003BOOK0000008', $trattoriaCustomers[0][0], '-1 day',  '19:00', '21:00', 'completed', 2],
    ['01JDEMO0003BOOK0000009', $trattoriaCustomers[1][0], '-2 days', '20:00', '22:00', 'completed', 4],
    ['01JDEMO0003BOOK0000010', $trattoriaCustomers[2][0], '-3 days', '19:30', '21:30', 'completed', 6],
    ['01JDEMO0003BOOK0000011', $trattoriaCustomers[1][0], '-5 days', '19:00', '21:00', 'no_show', 2],
    ['01JDEMO0003BOOK0000012', $trattoriaCustomers[3][0], '-7 days', '21:00', '22:30', 'completed', 3],
];
$stmt = $pdo->prepare("
    INSERT INTO bookings (id, tenant_id, booking_pattern, customer_id, start_datetime, end_datetime,
                          party_size, status, source, customer_timezone,
                          consent_given_at, consent_text_shown)
    VALUES (?, ?, 'capacity', ?, ?, ?, ?, ?, 'web', 'Europe/Rome',
            datetime('now'), 'I agree to the terms and conditions of this reservation.')
");
foreach ($trattoriaBookings as [$id, $custId, $dateOffset, $start, $end, $status, $partySize]) {
    $date = (clone $today)->modify($dateOffset)->format('Y-m-d');
    $stmt->execute([$id, $trattoriaId, $custId, "{$date} {$start}:00", "{$date} {$end}:00", $partySize, $status]);
}


// Trattoria Roma — capacity slots (dinner service windows)
$pdo->exec("DELETE FROM `capacity_slots` WHERE `tenant_id` = '{$trattoriaId}'");
$slotStmt = $pdo->prepare(
    "INSERT INTO `capacity_slots` (`id`, `tenant_id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `min_party_size`, `max_party_size`, `label`, `is_active`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
);
// [id, day_of_week, start_time, end_time, max_capacity, min_party_size, max_party_size, label]
$trattoriaSlots = [
    ['01JDEMO0003SLOT0000001', 0, '18:00:00', '19:30:00', 20, 1, 8, 'Early Dinner'],
    ['01JDEMO0003SLOT0000002', 0, '19:30:00', '21:00:00', 20, 1, 8, 'Main Dinner'],
    ['01JDEMO0003SLOT0000003', 0, '21:00:00', '22:30:00', 15, 2, 6, 'Late Dinner'],
    ['01JDEMO0003SLOT0000004', 1, '18:00:00', '19:30:00', 20, 1, 8, 'Early Dinner'],
    ['01JDEMO0003SLOT0000005', 1, '19:30:00', '21:00:00', 20, 1, 8, 'Main Dinner'],
    ['01JDEMO0003SLOT0000006', 1, '21:00:00', '22:30:00', 15, 2, 6, 'Late Dinner'],
    ['01JDEMO0003SLOT0000007', 2, '18:00:00', '19:30:00', 20, 1, 8, 'Early Dinner'],
    ['01JDEMO0003SLOT0000008', 2, '19:30:00', '21:00:00', 20, 1, 8, 'Main Dinner'],
    ['01JDEMO0003SLOT0000009', 2, '21:00:00', '22:30:00', 15, 2, 6, 'Late Dinner'],
    ['01JDEMO0003SLOT0000010', 3, '18:00:00', '19:30:00', 20, 1, 8, 'Early Dinner'],
    ['01JDEMO0003SLOT0000011', 3, '19:30:00', '21:00:00', 20, 1, 8, 'Main Dinner'],
    ['01JDEMO0003SLOT0000012', 3, '21:00:00', '22:30:00', 15, 2, 6, 'Late Dinner'],
    ['01JDEMO0003SLOT0000013', 4, '18:00:00', '19:30:00', 25, 1, 10, 'Early Dinner'],
    ['01JDEMO0003SLOT0000014', 4, '19:30:00', '21:00:00', 25, 1, 10, 'Main Dinner'],
    ['01JDEMO0003SLOT0000015', 4, '21:00:00', '22:30:00', 20, 2, 8, 'Late Dinner'],
    ['01JDEMO0003SLOT0000016', 5, '18:00:00', '19:30:00', 25, 1, 10, 'Early Dinner'],
    ['01JDEMO0003SLOT0000017', 5, '19:30:00', '21:00:00', 25, 1, 10, 'Main Dinner'],
    ['01JDEMO0003SLOT0000018', 5, '21:00:00', '22:30:00', 20, 2, 8, 'Late Dinner'],
];
foreach ($trattoriaSlots as [$slotId, $dow, $start, $end, $cap, $minParty, $maxParty, $label]) {
    $slotStmt->execute([$slotId, $trattoriaId, $dow, $start, $end, $cap, $minParty, $maxParty, $label]);
}

// Trattoria Roma — owner
$trattoriaOwnerId = '01JDEMO0003BUSER000001';
$pdo->prepare("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, 'owner')")
    ->execute([$trattoriaOwnerId, $trattoriaId, 'Roma Manager', 'owner@trattoria-roma.test', password_hash('welcome3210', PASSWORD_BCRYPT)]);
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'business_user', ?)")
    ->execute(['owner@trattoria-roma.test', $trattoriaOwnerId]);

// Trattoria Roma — availability (Tue-Sun 12:00-23:00, closed Monday)
$stmtAvail = $pdo->prepare("INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, NULL, ?, ?, ?, 1)");
for ($day = 1; $day <= 6; $day++) { // 1=Tue .. 6=Sun
    $stmtAvail->execute([sprintf('01JDEMO0003TAVAIL%05d', $day), $trattoriaId, $day, '12:00', '23:00']);
}

// ══════════════════════════════════════════════════════════════════════
// Tenant 4: Workshop Studio (event pattern)
// ══════════════════════════════════════════════════════════════════════

$workshopId = '01JDEMO0004TENANT00001';
$pdo->prepare("
    INSERT INTO tenants (id, name, slug, email, status, timezone, locale, currency,
                         date_format, number_format, time_format, week_start,
                         brand_color, booking_pattern,
                         require_phone, requires_consent, consent_text, cancellation_policy, confirmation_message)
    VALUES (?, 'Workshop Studio', 'workshop-studio', 'hello@workshopstudio.example', 'active',
            'Europe/Berlin', 'en', 'EUR',
            'Y-m-d', 'period', '24h', 1,
            '#8B5CF6', 'event',
            0, 1, 'I agree to the workshop terms and conditions.',
            'Full refund if cancelled 7 days before the event. 50% refund within 3-7 days. No refund within 3 days.',
            'You are registered! Check your email for event details and materials list.')
")->execute([$workshopId]);

// Workshop Studio — customers (7 for rich CRM)
$workshopCustomers = [
    ['01JDEMO0004CUST0000001', 'Hannah Weber', 'hannah.weber@example.com', null, 3],
    ['01JDEMO0004CUST0000002', 'Thomas Meier', 'thomas.meier@example.com', null, 2],
    ['01JDEMO0004CUST0000003', 'Lena Fischer', 'lena.fischer@example.com', '+49 170 000 0003', 2],
    ['01JDEMO0004CUST0000004', 'Max Schneider', 'max.schneider@example.com', '+49 170 000 0004', 1],
    ['01JDEMO0004CUST0000005', 'Sarah Hoffmann', 'sarah.hoffmann@example.com', null, 1],
    ['01JDEMO0004CUST0000006', 'Jonas Braun', 'jonas.braun@example.com', '+49 170 000 0006', 1],
    ['01JDEMO0004CUST0000007', 'Clara Vogel', 'clara.vogel@example.com', null, 0],
];
$stmt = $pdo->prepare("INSERT INTO customers (id, tenant_id, name, email, phone, booking_count) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($workshopCustomers as [$id, $name, $email, $phone, $bc]) {
    $stmt->execute([$id, $workshopId, $name, $email, $phone, $bc]);
}

// Workshop Studio — events (with per-booking spot limits)
// [id, name, desc, location, price, max_participants, min_spot_count, max_spot_count, date_offset, start, end, is_recurring, rrule, exceptions, allow_waitlist, waitlist_max]
$workshopEvents = [
    ['01JDEMO0004EVT00000001', 'Woodworking 101', 'Build your very first cutting board from European beech. All tools and materials provided.', 'Workshop Room A', 55.00, 12, 1, null, '+5 days', '09:00', '13:00', 0, null, null, 0, 0],
    ['01JDEMO0004EVT00000002', 'Watercolor Landscapes', 'Capture the essence of light and shadow in a full-day plein-air painting session.', 'Art Studio', 85.00, 8, 1, null, '+12 days', '10:00', '17:00', 0, null, null, 1, 3],
    ['01JDEMO0004EVT00000003', 'Morning Yoga Flow', 'Energizing vinyasa class suitable for all levels. Bring your own mat or borrow one from us.', 'Studio B', 15.00, 20, 1, 4, '+3 days', '08:00', '09:00', 1, 'FREQ=WEEKLY;COUNT=12', null, 1, 5],
    ['01JDEMO0004EVT00000004', 'Wine & Cheese Tasting', 'Explore five premier cru wines paired with artisan cheeses from the region.', 'Tasting Room', 45.00, 16, 1, 4, '+8 days', '19:00', '21:30', 0, null, null, 1, 4],
    ['01JDEMO0004EVT00000005', 'Pottery Wheel Basics', 'Get your hands dirty! Learn centering, pulling, and trimming on the wheel.', 'Ceramics Lab', 65.00, 4, 1, 2, '+15 days', '14:00', '18:00', 0, null, null, 1, 2],
];
$eventStmt = $pdo->prepare("
    INSERT INTO events (id, tenant_id, name, description, location, price,
                        max_participants, min_spot_count, max_spot_count, start_datetime, end_datetime,
                        is_recurring, rrule, exception_dates,
                        allow_waitlist, waitlist_max, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
");
foreach ($workshopEvents as [$eid, $name, $desc, $loc, $price, $maxP, $minSpots, $maxSpots, $dateOff, $start, $end, $isRec, $rrule, $exc, $wl, $wlMax]) {
    $date = (clone $today)->modify($dateOff)->format('Y-m-d');
    $eventStmt->execute([$eid, $workshopId, $name, $desc, $loc, $price, $maxP, $minSpots, $maxSpots,
                         "{$date} {$start}:00", "{$date} {$end}:00",
                         $isRec, $rrule, $exc, $wl, $wlMax]);
}

// Workshop Studio — bookings (10 total: 2 today yoga, 4 upcoming events, 2 waitlisted, 2 past)
$workshopBookings = [
    ['01JDEMO0004BOOK0000001', $workshopCustomers[0][0], '01JDEMO0004EVT00000003', '+0 days', '08:00', '09:00', 'confirmed', 1],
    ['01JDEMO0004BOOK0000002', $workshopCustomers[4][0], '01JDEMO0004EVT00000003', '+0 days', '08:00', '09:00', 'confirmed', 2],
    ['01JDEMO0004BOOK0000003', $workshopCustomers[1][0], '01JDEMO0004EVT00000001', '+5 days',  '09:00', '13:00', 'confirmed', 1],
    ['01JDEMO0004BOOK0000004', $workshopCustomers[2][0], '01JDEMO0004EVT00000002', '+12 days', '10:00', '17:00', 'confirmed', 1],
    ['01JDEMO0004BOOK0000005', $workshopCustomers[3][0], '01JDEMO0004EVT00000004', '+8 days',  '19:00', '21:30', 'confirmed', 2],
    ['01JDEMO0004BOOK0000006', $workshopCustomers[0][0], '01JDEMO0004EVT00000005', '+15 days', '14:00', '18:00', 'confirmed', 1],
    ['01JDEMO0004BOOK0000007', $workshopCustomers[5][0], '01JDEMO0004EVT00000005', '+15 days', '14:00', '18:00', 'waitlisted', 1],
    ['01JDEMO0004BOOK0000008', $workshopCustomers[1][0], '01JDEMO0004EVT00000004', '+8 days',  '19:00', '21:30', 'waitlisted', 2],
    ['01JDEMO0004BOOK0000009', $workshopCustomers[0][0], '01JDEMO0004EVT00000003', '-7 days',  '08:00', '09:00', 'completed', 1],
    ['01JDEMO0004BOOK0000010', $workshopCustomers[2][0], '01JDEMO0004EVT00000003', '-7 days',  '08:00', '09:00', 'completed', 1],
];
$stmt = $pdo->prepare("
    INSERT INTO bookings (id, tenant_id, booking_pattern, event_id, customer_id, start_datetime, end_datetime,
                          party_size, status, source, customer_timezone,
                          consent_given_at, consent_text_shown)
    VALUES (?, ?, 'event', ?, ?, ?, ?, ?, ?, 'web', 'Europe/Berlin',
            datetime('now'), 'I agree to the workshop terms and conditions.')
");
foreach ($workshopBookings as [$id, $custId, $eventId, $dateOffset, $start, $end, $status, $partySize]) {
    $date = (clone $today)->modify($dateOffset)->format('Y-m-d');
    $stmt->execute([$id, $workshopId, $eventId, $custId, "{$date} {$start}:00", "{$date} {$end}:00", $partySize, $status]);
}

// Workshop Studio — owner
$workshopOwnerId = '01JDEMO0004BUSER000001';
$pdo->prepare("INSERT INTO business_users (id, tenant_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?, 'owner')")
    ->execute([$workshopOwnerId, $workshopId, 'Workshop Admin', 'owner@workshop-studio.test', password_hash('welcome3210', PASSWORD_BCRYPT)]);
$pdo->prepare("INSERT INTO auth_emails (email, user_type, user_id) VALUES (?, 'business_user', ?)")
    ->execute(['owner@workshop-studio.test', $workshopOwnerId]);

// Workshop Studio — availability (Mon-Sat 09:00-18:00, closed Sunday)
$stmtAvail = $pdo->prepare("INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, NULL, ?, ?, ?, 1)");
for ($day = 0; $day <= 5; $day++) { // 0=Mon .. 5=Sat
    $stmtAvail->execute([sprintf('01JDEMO0004TAVAIL%05d', $day), $workshopId, $day, '09:00', '18:00']);
}

// ══════════════════════════════════════════════════════════════════════
// Business Applications — showcase the applications page
// ══════════════════════════════════════════════════════════════════════

$applications = [
    ['01JDEMO0005APP00000001', 'Bella Spa & Wellness', 'Isabella Martinez', 'isabella@bellaspa.example', '+34 600 000 001', 'https://bellaspa.example', 'We are a luxury day spa looking for a modern booking system for our 6 treatment rooms and 12 therapists.', 'pending', null, null],
    ['01JDEMO0005APP00000002', 'Nordic Fitness Club', 'Erik Lindgren', 'erik@nordicfitness.example', '+46 70 000 0002', 'https://nordicfitness.example', 'Fitness studio with group classes and personal training sessions. Currently using paper scheduling.', 'pending', null, null],
    ['01JDEMO0005APP00000003', 'CloudNine Photography', 'Priya Sharma', 'priya@cloudnine.example', null, 'https://cloudnine.example', 'Portrait and wedding photography studio. Need session booking with deposit handling.', 'approved', date('Y-m-d H:i:s', strtotime('-5 days')), $operatorId],
];

$appStmt = $pdo->prepare("INSERT INTO business_applications (id, business_name, contact_name, email, phone, website, message, status, reviewed_at, reviewed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$appDates = ['-2 days', '-4 days', '-8 days'];
foreach ($applications as $i => [$id, $biz, $name, $email, $phone, $web, $msg, $status, $reviewedAt, $reviewedBy]) {
    $createdAt = date('Y-m-d H:i:s', strtotime($appDates[$i]));
    $appStmt->execute([$id, $biz, $name, $email, $phone, $web, $msg, $status, $reviewedAt, $reviewedBy, $createdAt]);
}

// ══════════════════════════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════════════════════════

$tenantCount = (int) $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$customerCount = (int) $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$bookingCount = (int) $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$eventCount = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$businessUserCount = (int) $pdo->query("SELECT COUNT(*) FROM business_users")->fetchColumn();
$appCount = (int) $pdo->query("SELECT COUNT(*) FROM business_applications")->fetchColumn();

echo "✓ Demo database seeded at: {$demoDb}\n";
echo "  Operator:       demo@voxelbooking.com / welcome3210\n";
echo "  Tenants:        {$tenantCount}\n";
echo "    Demo Studio     (timeslot) — owner@demo-studio.test / welcome3210\n";
echo "    Hotel Marina    (resource) — owner@hotel-marina.test / welcome3210\n";
echo "    Trattoria Roma  (capacity) — owner@trattoria-roma.test / welcome3210\n";
echo "    Workshop Studio (event)    — owner@workshop-studio.test / welcome3210\n";
echo "  Services:       " . count($services) . " (timeslot only)\n";
echo "  Staff:          " . count($staffMembers) . " (timeslot only)\n";
echo "  Events:         {$eventCount} (event only)\n";
echo "  Customers:      {$customerCount}\n";
echo "  Bookings:       {$bookingCount}\n";
echo "  Business users: {$businessUserCount}\n";
echo "  Applications:   {$appCount}\n";
echo "  Audit entries:  " . count($logEntries) . "\n";

echo "  Email log:      " . count($emailEntries) . "\n";
echo "  Deletion queue: 1 (Sophie Brown)\n";

