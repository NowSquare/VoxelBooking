<?php

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS `customer_capabilities` (
        `id` CHAR(26) NOT NULL PRIMARY KEY,
        `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `purpose` VARCHAR(32) NOT NULL,
        `tenant_id` CHAR(26) NOT NULL,
        `subject_id` CHAR(26) NOT NULL,
        `customer_id` CHAR(26) NOT NULL,
        `created_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `revoked_at` DATETIME NULL,
        UNIQUE KEY `uq_customer_capability_hash` (`token_hash`),
        KEY `idx_customer_capability_subject` (`tenant_id`, `purpose`, `subject_id`),
        KEY `idx_customer_capability_customer` (`tenant_id`, `customer_id`),
        KEY `idx_customer_capability_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `customer_recovery_limits` (
        `bucket_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `attempts` INT UNSIGNED NOT NULL,
        `expires_at` DATETIME NOT NULL,
        KEY `idx_customer_recovery_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
