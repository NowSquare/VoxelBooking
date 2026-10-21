<?php

declare(strict_types=1);

$statements = [];
foreach ([
    'customer_name_snapshot' => 'VARCHAR(255)',
    'customer_email_snapshot' => 'VARCHAR(255)',
    'customer_phone_snapshot' => 'VARCHAR(50)',
    'capacity_slot_id' => 'CHAR(26)',
    'price_total' => 'DECIMAL(12,2)',
    'price_currency' => 'CHAR(3)',
] as $column => $type) {
    // MySQL commits DDL before the migration checkpoint. Each addition must
    // therefore be safe to resume after a process stops partway through.
    $statements[] = "SET @vb_booking_column_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = '$column'), 'DO 0', 'ALTER TABLE `bookings` ADD COLUMN `$column` $type NULL')";
    $statements[] = 'PREPARE vb_booking_column_stmt FROM @vb_booking_column_sql';
    $statements[] = 'EXECUTE vb_booking_column_stmt';
    $statements[] = 'DEALLOCATE PREPARE vb_booking_column_stmt';
}

return $statements;
