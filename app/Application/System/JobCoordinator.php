<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Business\BusinessAccess as A;
use App\Application\Shared\Access;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Outbox;
use App\Engine\Ulid;

/** Durable requests; external cron or the native operator action dispatches after commit. */
final class JobCoordinator
{
    public const JOBS = [
        'retention',
        'reminders',
        'deliveries',
        'oauth_cleanup',
        'customer_access_cleanup',
        'media_cleanup',
        'webhook_cleanup',
        'outbox_cleanup'
    ];

    public static function preview(ActorContext $actor, array $input): array
    {
        A::actor($actor, 'jobs:run', write: true);
        return [
            'jobs' => self::jobs($input),
            'scope' => 'installation',
            'execution' => 'next_dispatch',
            'effects' => [
                'retention_applies_configured_policy',
                'due_notifications_may_be_sent',
                'expired_records_may_be_deleted'
            ],
            'overlap_protection' => true
        ];
    }

    public static function request(ActorContext $actor, array $input): array
    {
        $actor = A::actor($actor, 'jobs:run', write: true);
        return Access::transaction(function () use ($actor, $input): array {
            $id = self::insert(self::jobs($input), $actor);
            A::audit($actor, 'system.jobs_requested', 'job_request', $id);
            return self::row($id);
        });
    }

    private static function insert(array $jobs, ?ActorContext $actor): string
    {
        $id = Ulid::generate();
        Database::execute(
            'INSERT INTO system_job_requests (id,jobs_json,principal_type,principal_id,connection_id,credential_id,auth_mode,requested_at) VALUES (?,?,?,?,?,?,?,?)',
            [
                $id,
                json_encode($jobs, JSON_THROW_ON_ERROR),
                $actor?->principalType ?? 'system',
                $actor?->principalId ?? 'scheduler',
                $actor?->connectionId,
                $actor?->credentialId,
                $actor?->authMode ?? 'scheduled',
                gmdate('Y-m-d H:i:s')
            ]
        );
        return $id;
    }

    public static function get(ActorContext $actor, array $input): array
    {
        A::actor($actor, 'jobs:read');
        return self::row((string) ($input['id'] ?? ''));
    }

    public static function status(ActorContext $actor, array $input = []): array
    {
        A::actor($actor, 'jobs:read');
        $latest = Database::query('SELECT * FROM system_job_requests ORDER BY requested_at DESC,id DESC LIMIT 1')[0]
            ?? null;
        $last = Database::query(
            "SELECT finished_at,status,last_error_code FROM system_job_requests WHERE finished_at IS NOT NULL ORDER BY finished_at DESC,id DESC LIMIT 1"
        )[0] ?? null;
        $queue = (int) Database::query("SELECT COUNT(*) AS n FROM system_job_requests WHERE status='queued'")[0]['n'];
        $expected = $last !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($last['finished_at'] . ' UTC') + 300) : null;
        return [
            'state' => $latest['status'] ?? 'never_run',
            'last_run_at' => self::date($last['finished_at'] ?? null),
            'next_expected_at' => $expected,
            'scheduler' => 'external_cron',
            'recommended_interval_seconds' => 300,
            'queued_requests' => $queue,
            'last_error_code' => $last['last_error_code'] ?? null,
            'latest' => $latest ? self::project($latest) : null
        ];
    }

    public static function lockName(): string
    {
        return 'vb_jobs_'
            . substr(hash('sha256', (string) (Database::query('SELECT DATABASE() AS name')[0]['name'] ?? '')), 0, 40);
    }

    /** A held connection owns this lock for the entire batch, including network calls. */
    public static function dispatch(bool $scheduled = false): array
    {
        if (Database::pdo()->inTransaction()) {
            throw new \LogicException('Maintenance dispatch requires a committed transaction.');
        }

        if (\App\Engine\DemoMode::isActive()) {
            return ['status' => 'suppressed', 'requests' => []];
        }

        $lock = self::lockName();
        if ((int) Database::query('SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired'] !== 1) {
            return ['status' => 'busy', 'requests' => []];
        }

        try {
            // Holding the lock proves any earlier running request lost its worker.
            Database::execute(
                "UPDATE system_job_requests SET status='queued',last_error_code='worker_interrupted' WHERE status='running' AND attempts<3"
            );
            Database::execute(
                "UPDATE system_job_requests SET status='failed',last_error_code='worker_interrupted',finished_at=? WHERE status='running'",
                [gmdate('Y-m-d H:i:s')]
            );
            if ($scheduled) {
                self::insert(self::JOBS, null);
            }

            $requests = Database::query(
                "SELECT * FROM system_job_requests WHERE status='queued' ORDER BY requested_at,id LIMIT 5"
            );
            $results = [];
            $partial = false;
            foreach ($requests as $row) {
                try {
                    if ($row['principal_type'] !== 'system') {
                        A::actor(
                            new ActorContext(
                                $row['principal_type'],
                                $row['principal_id'],
                                'operator',
                                [],
                                ['jobs:run'],
                                $row['connection_id'],
                                $row['credential_id'],
                                $row['auth_mode']
                            ),
                            'jobs:run',
                            write: true
                        );
                    }
                } catch (OperationException) {
                    Database::execute(
                        "UPDATE system_job_requests SET status='cancelled',last_error_code='requester_access_revoked',finished_at=? WHERE id=?",
                        [gmdate('Y-m-d H:i:s'), $row['id']]
                    );
                    $results[] = self::row($row['id']);
                    continue;
                }

                Database::execute(
                    "UPDATE system_job_requests SET status='running',started_at=?,finished_at=NULL,attempts=attempts+1,last_error_code=NULL WHERE id=?",
                    [gmdate('Y-m-d H:i:s'), $row['id']]
                );
                $prior = json_decode($row['results_json'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $outcomes = [];
                $errors = false;
                foreach (json_decode($row['jobs_json'], true, flags: JSON_THROW_ON_ERROR) as $job) {
                    // A recovered worker does not repeat a completed job in the same request.
                    $done = array_values(array_filter(
                        $prior,
                        static fn(array $r): bool => $r['job'] === $job && $r['status'] === 'completed'
                    ));
                    if ($done !== []) {
                        $outcomes[] = $done[0];
                        continue;
                    }

                    try {
                        $effect = self::run($job);
                        $failed = ($effect['failures'] ?? 0) > 0;
                        $outcomes[] = [
                            'job' => $job,
                            'status' => $failed ? 'partial' : 'completed',
                            'effects' => $effect,
                            'error_code' => $failed ? 'job_partial_failure' : null
                        ];
                        $errors = $errors || $failed;
                    } catch (\Throwable) {
                        $errors = true;
                        $outcomes[] = [
                            'job' => $job,
                            'status' => 'failed',
                            'effects' => ['failures' => 1],
                            'error_code' => 'job_failed'
                        ];
                    }

                    Database::execute(
                        'UPDATE system_job_requests SET results_json=? WHERE id=?',
                        [json_encode($outcomes, JSON_THROW_ON_ERROR), $row['id']]
                    );
                }

                $state = $errors ? 'partial' : 'completed';
                $partial = $partial || $errors;
                Database::execute(
                    'UPDATE system_job_requests SET status=?,finished_at=?,results_json=?,last_error_code=? WHERE id=?',
                    [
                        $state,
                        gmdate('Y-m-d H:i:s'),
                        json_encode($outcomes, JSON_THROW_ON_ERROR),
                        $errors ? 'job_partial_failure' : null,
                        $row['id']
                    ]
                );
                AuditLog::log(
                    'system.jobs_completed',
                    'job_request',
                    $row['id'],
                    [
                        'result' => $state,
                        'connection_id' => $row['connection_id'],
                        'jobs' => array_column($outcomes, 'job')
                    ],
                    actorType: $row['principal_type'],
                    actorId: $row['principal_id']
                );
                $results[] = self::row($row['id']);
            }

            if ($requests !== []) {
                Database::upsertSetting('cron_last_run', gmdate('Y-m-d H:i:s'));
            }

            return ['status' => $partial ? 'partial' : 'ok', 'requests' => $results];
        } finally {
            Database::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function run(string $job): array
    {
        if ($job === 'retention') {
            $raw = \App\Engine\RetentionJob::run();
            $processed = 0;
            $skipped = 0;
            $failures = count($raw['errors'] ?? []);
            foreach ($raw['anonymization'] ?? [] as $counts) {
                $processed += (int) ($counts['processed'] ?? 0);
                $skipped += (int) ($counts['skipped'] ?? 0);
                $failures += (int) ($counts['errors'] ?? 0);
            }

            return [
                'customers_anonymized' => $processed,
                'customers_skipped' => $skipped,
                'audit_deleted' => (int) $raw['audit_cleanup'],
                'email_logs_deleted' => (int) $raw['email_cleanup'],
                'rate_limits_deleted' => (int) $raw['rate_limit_cleanup'],
                'failures' => $failures
            ];
        }

        if ($job === 'reminders') {
            $r = \App\Engine\ReminderJob::run();
            return [
                'queued' => (int) $r['queued'],
                'sent' => (int) $r['sent'],
                'skipped' => (int) $r['skipped'],
                'failures' => (int) $r['failed'] + count($r['errors'] ?? [])
            ];
        }

        if ($job === 'deliveries') {
            $r = Outbox::dispatch(100);
            return $r + ['failures' => $r['failed'] + $r['retrying']];
        }

        if ($job === 'oauth_cleanup') {
            return \App\Agent\OAuth\Maintenance::cleanup();
        }

        if ($job === 'customer_access_cleanup') {
            return ['deleted' => \App\Engine\CustomerCapability::cleanup()];
        }

        if ($job === 'media_cleanup') {
            return \App\Application\Media\MediaService::cleanup();
        }

        if ($job === 'webhook_cleanup') {
            \App\Application\Integrations\WebhookService::cleanup();
            return ['completed' => 1];
        }

        if ($job === 'outbox_cleanup') {
            Outbox::cleanup();
            return ['completed' => 1];
        }

        throw new \LogicException('Unsupported job.');
    }

    private static function jobs(array $input): array
    {
        $jobs = $input['jobs'] ?? self::JOBS;
        if (!is_array($jobs) || $jobs === [] || array_diff($jobs, self::JOBS) !== []) {
            A::invalid('jobs');
        }

        return array_values(array_unique($jobs));
    }

    private static function row(string $id): array
    {
        $row = Database::query('SELECT * FROM system_job_requests WHERE id=?', [$id])[0] ?? null;
        if ($row === null) {
            throw new OperationException('not_found', 404);
        }

        return self::project($row);
    }

    private static function project(array $row): array
    {
        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'jobs' => json_decode($row['jobs_json'], true, flags: JSON_THROW_ON_ERROR),
            'requested_at' => self::date($row['requested_at']),
            'started_at' => self::date($row['started_at']),
            'finished_at' => self::date($row['finished_at']),
            'attempts' => (int) $row['attempts'],
            'results' => json_decode($row['results_json'] ?? '[]', true, flags: JSON_THROW_ON_ERROR),
            'last_error_code' => $row['last_error_code']
        ];
    }

    private static function date(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
    }
}
