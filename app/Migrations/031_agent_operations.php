<?php

declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS `agent_operation_receipts` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `dedupe_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `principal_type` VARCHAR(20) NOT NULL, `principal_id` VARCHAR(128) NOT NULL,
        `connection_id` CHAR(26) NULL, `credential_id` CHAR(26) NULL, `tenant_id` CHAR(26) NULL,
        `operation_id` VARCHAR(100) NOT NULL,
        `result_cipher` MEDIUMTEXT NULL, `resource_id` CHAR(26) NULL,
        `created_at` DATETIME NOT NULL, `replay_until` DATETIME NOT NULL,
        UNIQUE KEY `receipt_dedupe` (`dedupe_hash`),
        KEY `receipt_principal` (`principal_type`, `principal_id`),
        KEY `receipt_tenant` (`tenant_id`), KEY `receipt_retention` (`replay_until`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `agent_previews` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `handle_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `principal_type` VARCHAR(20) NOT NULL, `principal_id` VARCHAR(128) NOT NULL,
        `connection_id` CHAR(26) NULL, `credential_id` CHAR(26) NULL, `tenant_id` CHAR(26) NULL,
        `operation_id` VARCHAR(100) NOT NULL, `request_hash` CHAR(64) NOT NULL,
        `payload_cipher` MEDIUMTEXT NOT NULL, `review_cipher` MEDIUMTEXT NOT NULL,
        `approved_at` DATETIME NULL, `approval_source` VARCHAR(30) NULL,
        `consumed_at` DATETIME NULL, `expires_at` DATETIME NOT NULL, `created_at` DATETIME NOT NULL,
        UNIQUE KEY `preview_handle` (`handle_hash`), KEY `preview_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
