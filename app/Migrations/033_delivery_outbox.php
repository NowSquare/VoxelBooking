<?php

declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS `delivery_outbox` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `topic` VARCHAR(100) NOT NULL, `tenant_id` CHAR(26) NULL,
        `operation_receipt_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
        `dedupe_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `payload_cipher` MEDIUMTEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `available_at` DATETIME NOT NULL, `lease_until` DATETIME NULL,
        `lease_token` CHAR(64) NULL, `delivered_at` DATETIME NULL,
        `last_error_code` VARCHAR(60) NULL, `created_at` DATETIME NOT NULL,
        UNIQUE KEY `outbox_dedupe` (`dedupe_hash`),
        KEY `outbox_ready` (`status`, `available_at`, `lease_until`), KEY `outbox_tenant` (`tenant_id`),
        KEY `outbox_receipt` (`operation_receipt_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
