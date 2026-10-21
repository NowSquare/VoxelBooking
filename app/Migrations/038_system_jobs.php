<?php

declare(strict_types=1);
return [
    "INSERT IGNORE INTO `settings` (`key`,`value`) VALUES ('system_settings_revision','0')",
    "CREATE TABLE IF NOT EXISTS `system_job_requests` (
        `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        `status` VARCHAR(20) NOT NULL DEFAULT 'queued', `jobs_json` JSON NOT NULL,
        `principal_type` VARCHAR(20) NOT NULL, `principal_id` VARCHAR(128) NOT NULL,
        `connection_id` CHAR(26) NULL, `credential_id` CHAR(26) NULL,
        `auth_mode` VARCHAR(30) NOT NULL, `requested_at` DATETIME NOT NULL,
        `started_at` DATETIME NULL, `finished_at` DATETIME NULL,
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0, `results_json` JSON NULL,
        `last_error_code` VARCHAR(80) NULL,
        KEY `jobs_status` (`status`,`requested_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
