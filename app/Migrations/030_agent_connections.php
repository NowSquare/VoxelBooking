<?php

declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS `agent_connections` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `principal_type` VARCHAR(20) NOT NULL,
        `principal_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `identity_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `customer_capability_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
        `name` VARCHAR(120) NOT NULL,
        `scopes` JSON NOT NULL,
        `business_ids` JSON NOT NULL,
        `client_id` VARCHAR(512) NULL,
        `purpose` VARCHAR(500) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `revoked_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `last_used_at` DATETIME NULL,
        KEY `connection_principal` (`principal_type`, `principal_id`),
        KEY `connection_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS `agent_credentials` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `connection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `prefix` VARCHAR(12) NOT NULL,
        `audience` VARCHAR(512) NOT NULL,
        `scopes` JSON NULL,
        `expires_at` DATETIME NOT NULL,
        `revoked_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        UNIQUE KEY `credential_hash` (`token_hash`),
        KEY `credential_connection` (`connection_id`),
        CONSTRAINT `credential_connection_fk` FOREIGN KEY (`connection_id`) REFERENCES `agent_connections` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
