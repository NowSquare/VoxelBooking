<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Agent\OperationDefinition;
use App\Application\Shared\Contract as S;

final class SystemOperations
{
    public static function definitions(): array
    {
        $text = S::text(...);
        $date = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $boolean = ['type' => 'boolean'];
        $integer = ['type' => 'integer', 'minimum' => 0];
        $section = ['type' => 'string', 'enum' => ['general', 'mail']];
        $version = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $general = array_fill_keys(SystemSettings::GENERAL, $text(2048));
        $general['enable_applications'] = $boolean;
        $mail = array_fill_keys(SystemSettings::MAIL, $text(2048));
        $settings = S::object([
            'section' => $section,
            'values' => ['oneOf' => [S::object($general), S::object($mail)]],
            'credentials_configured' => $boolean,
            'credential_handoff' => $text(2048, true),
            'version' => $version
        ]);
        $update = [
            'type' => 'object',
            'oneOf' => [
                S::object([
                    'section' => ['type' => 'string', 'const' => 'general'],
                    'expected_version' => $version,
                    'changes' => S::object($general, [])
                ]),
                S::object([
                    'section' => ['type' => 'string', 'const' => 'mail'],
                    'expected_version' => $version,
                    'changes' => S::object($mail, [])
                ])
            ]
        ];
        $jobs = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => count(JobCoordinator::JOBS),
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'enum' => JobCoordinator::JOBS]
        ];
        $effects = ['type' => 'object', 'additionalProperties' => $integer];
        $result = S::object([
            'job' => $text(50),
            'status' => $text(20),
            'effects' => $effects,
            'error_code' => $text(80, true)
        ]);
        $job = S::object([
            'id' => $text(26),
            'status' => [
                'type' => 'string',
                'enum' => ['queued', 'running', 'completed', 'partial', 'failed', 'cancelled']
            ],
            'jobs' => $jobs,
            'requested_at' => $date,
            'started_at' => $date,
            'finished_at' => $date,
            'attempts' => $integer,
            'results' => ['type' => 'array', 'items' => $result],
            'last_error_code' => $text(80, true)
        ]);
        $nullableJob = $job;
        $nullableJob['type'] = ['object', 'null'];
        $status = S::object([
            'state' => $text(20),
            'last_run_at' => $date,
            'next_expected_at' => $date,
            'scheduler' => ['type' => 'string', 'const' => 'external_cron'],
            'recommended_interval_seconds' => $integer,
            'queued_requests' => $integer,
            'last_error_code' => $text(80, true),
            'latest' => $nullableJob
        ]);
        $filters = [
            'business_filter' => $text(26),
            'action' => $text(128),
            'principal_type' => $text(30),
            'principal_id' => $text(128),
            'entity_type' => $text(128),
            'entity_id' => $text(128),
            'connection_id' => $text(26),
            'result' => $text(30),
            'correlation_id' => $text(128),
            'from' => ['type' => 'string', 'format' => 'date-time'],
            'until' => ['type' => 'string', 'format' => 'date-time']
        ];
        $audit = S::object([
            'id' => $text(26),
            'business_id' => $text(26, true),
            'action' => $text(128),
            'entity_type' => $text(128),
            'entity_id' => $text(128, true),
            'actor_type' => $text(30),
            'actor_id' => $text(128, true),
            'connection_id' => $text(128, true),
            'receipt_id' => $text(128, true),
            'result' => $text(128, true),
            'correlation_id' => $text(128, true),
            'created_at' => $date
        ]);
        return [
            self::op(
                'system_settings_get',
                'Read system settings',
                'C35',
                ['system:read'],
                true,
                S::object(['section' => $section], []),
                $settings,
                SystemSettings::get(...)
            ),
            self::op(
                'system_settings_update',
                'Update system settings',
                'C35',
                ['system:manage'],
                false,
                $update,
                $settings,
                SystemSettings::update(...),
                SystemSettings::preview(...),
                true
            ),
            self::op(
                'jobs_status',
                'Read maintenance status',
                'C36',
                ['jobs:read'],
                true,
                S::object([], []),
                $status,
                JobCoordinator::status(...)
            ),
            self::op(
                'job_request_get',
                'Read maintenance request',
                'C36',
                ['jobs:read'],
                true,
                S::object(['id' => $text(26)]),
                $job,
                JobCoordinator::get(...)
            ),
            self::op(
                'jobs_run',
                'Schedule maintenance now',
                'C36',
                ['jobs:run'],
                false,
                S::object(['jobs' => $jobs], []),
                $job,
                JobCoordinator::request(...),
                JobCoordinator::preview(...),
                true
            ),
            self::op(
                'audit_list',
                'Read redacted audit events',
                'C37',
                ['audit:read'],
                true,
                S::object(
                    [
                        ...$filters,
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'cursor' => $text(4096)
                    ],
                    []
                ),
                S::object([
                    'items' => ['type' => 'array', 'maxItems' => 100, 'items' => $audit],
                    'next_cursor' => $text(4096, true)
                ]),
                SystemAudit::list(...)
            ),
            self::op(
                'audit_export',
                'Export redacted audit events',
                'C37',
                ['audit:export'],
                true,
                S::object($filters, []),
                S::object([
                    'filename' => $text(255),
                    'content_type' => $text(100),
                    'row_count' => $integer,
                    'content' => $text(8000000)
                ]),
                SystemAudit::export(...)
            ),
            self::op(
                'system_status',
                'Read installation readiness',
                'C38',
                ['system:read'],
                true,
                S::object([], []),
                S::object([
                    'version' => $text(50),
                    'installed' => $boolean,
                    'schema_version' => $integer,
                    'pending_migrations' => $boolean,
                    'php_supported' => $boolean,
                    'zip_updates_available' => $boolean,
                    'update_handoff' => $text(2048),
                    'install_handoff' => $text(2048),
                    'update_policy' => $text(80),
                    'remote_update_checked' => $boolean
                ]),
                SystemInfo::status(...)
            ),
            self::op(
                'system_handoff',
                'Open a trusted system task',
                'C38',
                ['system:read'],
                true,
                S::object(['task' => [
                    'type' => 'string',
                    'enum' => ['mail_credentials', 'cron_credentials', 'raw_logs', 'update', 'installation']
                ]]),
                S::object([
                    'task' => $text(50),
                    'url' => $text(2048),
                    'authentication' => $text(50),
                    'expires_at' => $date,
                    'reason' => $text(500)
                ]),
                SystemInfo::handoff(...)
            ),
            self::op(
                'operator_business_context',
                'Resolve operator business context',
                'C39',
                ['businesses:read'],
                true,
                S::object(['business_id' => $text(26)], ['business_id']),
                S::object([
                    'business_id' => $text(26),
                    'business_name' => $text(255),
                    'timezone' => $text(100),
                    'principal_type' => $text(30),
                    'principal_id' => $text(128),
                    'role' => $text(30),
                    'context_model' => $text(50),
                    'browser_session_changed' => $boolean
                ]),
                SystemInfo::businessContext(...)
            ),
        ];
    }

    private static function op(
        string $id,
        string $title,
        string $capability,
        array $scopes,
        bool $read,
        array $input,
        array $output,
        \Closure $handler,
        ?\Closure $preview = null,
        bool $review = false
    ): OperationDefinition {
        return new OperationDefinition(
            operationId: $id,
            title: $title,
            description: $title . '. ' . match ($capability) {
                'C35' => 'Operator-only, nonsecret configuration. Credentials are entered in the authenticated browser.',
                'C36' => 'Installation-wide maintenance; run requests commit before execution by the external cron dispatcher. Status reports real outcomes and prevents overlapping workers.',
                'C37' => 'Dedicated audit permission. Results omit raw details and IP addresses and respect granted business boundaries.',
                'C39' => 'Retains the original operator identity. Does not create an impersonation session.',
                default => 'Reports local readiness only; credentials, raw logs and code installation require the trusted browser or host.'
            },
            inputSchema: $input,
            outputSchema: $output,
            requiredScopes: $scopes,
            actorPolicy: 'operator',
            readOnly: $read,
            destructive: false,
            confirmationPolicy: $review ? 'review' : 'none',
            patterns: [],
            documentationPage: $capability === 'C37' ? 'reports-and-audit' : 'hosting-and-troubleshooting',
            capabilityIds: $id === 'system_handoff' ? ['C35', 'C37', 'C38'] : [$capability],
            acceptanceTestIds: array_values(array_unique([
                'A04',
                'A41',
                'A46',
                'A47',
                ...match ($capability) {
                    'C35' => ['A03', 'A16', 'A38'],
                    'C36' => ['A13', 'A20', 'A37'],
                    'C37' => ['A02', 'A36', 'A40'],
                    'C38' => ['A38'],
                    default => ['A02', 'A03']
                }
            ])),
            httpMethod: $read ? 'GET' : 'POST',
            httpPath: '/operations/' . $id,
            handler: $handler,
            sideEffects: match ($id) {
                'jobs_run' => ['scheduled_maintenance'],
                'system_settings_update' => ['audit'],
                default => []
            },
            errorCodes: [
                'forbidden',
                'not_found',
                'version_conflict',
                'validation_failed',
                'insufficient_scope',
                'export_too_large'
            ],
            demoAllowed: $read,
            previewHandler: $preview,
            // A settings save rotates the revision, so repeating it meets version_conflict; each run request queues new work.
            idempotencyPolicy: $id === 'system_settings_update' ? 'natural' : null
        );
    }
}
