<?php

declare(strict_types=1);

/*
 * Separate the review secret from the commit handle.
 *
 * An agent receives a commit handle for itself and a review link for the
 * person. Storing a distinct hash for the link's secret means the link cannot
 * commit and the handle cannot open the review. Previews created before this
 * migration keep a NULL review hash: their links stop opening, and the agent
 * refreshes the preview, which expires within ten minutes anyway.
 *
 * MySQL commits DDL before the migration checkpoint, so each step is safe to
 * resume after a process stops partway through.
 */
return [
    "SET @vb_review_column_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'agent_previews' AND column_name = 'review_hash'), 'DO 0', 'ALTER TABLE `agent_previews` ADD COLUMN `review_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `handle_hash`')",
    'PREPARE vb_review_column_stmt FROM @vb_review_column_sql',
    'EXECUTE vb_review_column_stmt',
    'DEALLOCATE PREPARE vb_review_column_stmt',
    "SET @vb_review_index_sql = IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'agent_previews' AND index_name = 'uq_agent_preview_review'), 'DO 0', 'ALTER TABLE `agent_previews` ADD UNIQUE KEY `uq_agent_preview_review` (`review_hash`)')",
    'PREPARE vb_review_index_stmt FROM @vb_review_index_sql',
    'EXECUTE vb_review_index_stmt',
    'DEALLOCATE PREPARE vb_review_index_stmt',
];
