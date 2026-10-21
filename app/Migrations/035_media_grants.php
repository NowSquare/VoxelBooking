<?php

declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS `agent_media_grants` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `tenant_id` CHAR(26) NOT NULL, `principal_type` VARCHAR(20) NOT NULL, `principal_id` VARCHAR(128) NOT NULL,
        `connection_id` CHAR(26) NULL, `credential_id` CHAR(26) NULL, `identity_hash` CHAR(64) NOT NULL,
        `purpose` VARCHAR(20) NOT NULL, `token_hash` CHAR(64) NOT NULL,
        `path` VARCHAR(500) NULL, `content_hash` CHAR(64) NULL, `consumed_at` DATETIME NULL,
        `expires_at` DATETIME NOT NULL, `created_at` DATETIME NOT NULL,
        KEY `media_tenant` (`tenant_id`), KEY `media_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
