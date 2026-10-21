<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Agent\ActorContext;
use App\Agent\OperationDefinition;

final class CatalogOperations
{
    public static function definitions(): array
    {
        $definitions = [];
        $business = ['business_id' => [
            'type' => 'string',
            'maxLength' => 26,
            'description' => __('agent.contract.business_id')
        ]];
        $identity = ['id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 26]];
        $version = ['expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']];
        foreach (CatalogFields::TYPES as $kind => $metadata) {
            $properties = CatalogFields::properties($kind);
            $output = self::recordSchema($kind);
            $definitions[] = self::definition(
                $kind . '_list',
                'List ' . str_replace('_', ' ', $kind),
                $metadata,
                false,
                [
                    ...$business,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'cursor' => ['type' => 'string', 'maxLength' => 4096],
                    ...($kind === 'blocked_dates' ? [] : ['is_active' => ['type' => 'boolean']])
                ],
                [],
                self::object([
                    'items' => ['type' => 'array', 'items' => $output],
                    'next_cursor' => ['type' => ['string', 'null']]
                ], ['items', 'next_cursor']),
                static fn (ActorContext $actor, array $input): array => CatalogService::list($actor, $kind, $input)
            );
            $definitions[] = self::definition(
                $metadata['entity'] . '_get',
                'Get ' . str_replace('_', ' ', $metadata['entity']),
                $metadata,
                false,
                [...$business, ...$identity],
                ['id'],
                $output,
                static fn (ActorContext $actor, array $input): array => CatalogService::get($actor, $kind, $input)
            );
            $media = in_array($kind, ['services', 'staff', 'resources', 'events'], true)
                ? ['media_id' => ['type' => 'string', 'maxLength' => 26], 'remove_image' => ['type' => 'boolean']]
                : [];
            $definitions[] = self::definition(
                $metadata['entity'] . '_create',
                'Create ' . str_replace('_', ' ', $metadata['entity']),
                $metadata,
                true,
                [...$business, ...$properties, ...$media],
                CatalogFields::required($kind),
                $output,
                static fn (ActorContext $actor, array $input): array => CatalogService::create($actor, $kind, $input),
                review: $kind === 'blocked_dates',
                preview: static fn (ActorContext $actor, array $input): array =>
                    CatalogService::preview($actor, $kind, 'create', $input)
            );
            if (!in_array($kind, ['capacity_slots', 'blocked_dates'], true)) {
                $definitions[] = self::definition(
                    $metadata['entity'] . '_update',
                    'Update ' . str_replace('_', ' ', $metadata['entity']),
                    $metadata,
                    true,
                    [...$business, ...$identity, ...$version, ...$properties, ...$media],
                    ['id', 'expected_version'],
                    $output,
                    static fn (ActorContext $actor, array $input): array =>
                        CatalogService::update($actor, $kind, $input),
                    preview: static fn (ActorContext $actor, array $input): array =>
                        CatalogService::preview($actor, $kind, 'update', $input)
                );
            }
            if ($kind !== 'blocked_dates') {
                $definitions[] = self::definition(
                    $metadata['entity'] . '_set_active',
                    'Set ' . str_replace('_', ' ', $metadata['entity']) . ' active state',
                    $metadata,
                    true,
                    [...$business, ...$identity, ...$version, 'is_active' => ['type' => 'boolean']],
                    ['id', 'expected_version', 'is_active'],
                    $output,
                    static fn (ActorContext $actor, array $input): array =>
                        CatalogService::setActive($actor, $kind, $input),
                    demoAllowed: in_array($kind, ['services', 'staff', 'resources'], true),
                    preview: static fn (ActorContext $actor, array $input): array =>
                        CatalogService::preview($actor, $kind, 'set_active', $input)
                );
            }
            if (in_array($kind, ['services', 'staff', 'resources'], true)) {
                $definitions[] = self::definition(
                    $metadata['entity'] . '_reorder',
                    'Reorder ' . str_replace('_', ' ', $metadata['entity']),
                    $metadata,
                    true,
                    [
                        ...$business,
                        ...$identity,
                        ...$version,
                        'direction' => ['type' => 'string', 'enum' => ['up', 'down']]
                    ],
                    ['id', 'expected_version', 'direction'],
                    $output,
                    static fn (ActorContext $actor, array $input): array =>
                        CatalogService::reorder($actor, $kind, $input)
                );
            }
            if (in_array($kind, ['capacity_slots', 'events', 'blocked_dates'], true)) {
                $definitions[] = self::definition(
                    $metadata['entity'] . '_delete',
                    'Delete ' . str_replace('_', ' ', $metadata['entity']),
                    $metadata,
                    true,
                    [...$business, ...$identity, ...$version],
                    ['id', 'expected_version'],
                    self::object([
                        'id' => ['type' => 'string'],
                        'business_id' => ['type' => 'string'],
                        'deleted' => ['type' => 'boolean', 'const' => true]
                    ], ['id', 'business_id', 'deleted']),
                    static fn (ActorContext $actor, array $input): array =>
                        CatalogService::delete($actor, $kind, $input),
                    destructive: true,
                    review: true,
                    preview: static fn (ActorContext $actor, array $input): array =>
                        CatalogService::preview($actor, $kind, 'delete', $input)
                );
            }
        }
        $metadata = ['scope' => 'availability', 'patterns' => ['timeslot'], 'capability' => 'C14'];
        $scheduleIdentity = [...$business, 'staff_id' => ['type' => ['string', 'null'], 'maxLength' => 26]];
        $window = self::object(
            [
                'day_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
                'start_time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9](:00)?$'],
                'end_time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9](:00)?$']
            ],
            ['day_of_week', 'start_time', 'end_time']
        );
        $schedule = self::object(
            [
                'business_id' => ['type' => 'string'],
                'staff_id' => ['type' => ['string', 'null']],
                'timezone' => ['type' => 'string'],
                'inherits_business_schedule' => ['type' => 'boolean'],
                'version' => ['type' => 'string'],
                'windows' => ['type' => 'array', 'maxItems' => 100, 'items' => $window],
            ],
            ['business_id', 'staff_id', 'timezone', 'inherits_business_schedule', 'version', 'windows']
        );
        $definitions[] = self::definition(
            'schedule_get',
            'Get weekly schedule',
            $metadata,
            false,
            $scheduleIdentity,
            [],
            $schedule,
            static fn (ActorContext $actor, array $input): array => CatalogService::getSchedule($actor, $input)
        );
        $definitions[] = self::definition(
            'schedule_replace',
            'Replace weekly schedule',
            $metadata,
            true,
            [...$scheduleIdentity, ...$version, 'windows' => $schedule['properties']['windows']],
            ['expected_version', 'windows'],
            $schedule,
            static fn (ActorContext $actor, array $input): array => CatalogService::saveSchedule($actor, $input),
            preview: static fn (ActorContext $actor, array $input): array =>
                CatalogService::previewSchedule($actor, $input)
        );
        $definitions[] = self::definition(
            'schedule_reset_staff',
            'Reset staff schedule to business hours',
            $metadata,
            true,
            [...$scheduleIdentity, ...$version],
            ['staff_id', 'expected_version'],
            $schedule,
            static fn (ActorContext $actor, array $input): array => CatalogService::resetSchedule($actor, $input),
            preview: static fn (ActorContext $actor, array $input): array =>
                CatalogService::previewSchedule($actor, $input, true)
        );

        return $definitions;
    }

    public static function recordSchema(string $kind): array
    {
        $properties = [
            'id' => ['type' => 'string'],
            'business_id' => ['type' => 'string'],
            ...CatalogFields::properties($kind),
            'version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
        ];
        if ($kind !== 'blocked_dates') {
            $properties['is_active'] = ['type' => 'boolean'];
        }
        if (in_array($kind, ['services', 'staff', 'resources', 'events'], true)) {
            $properties['sort_order'] = ['type' => 'integer'];
        }
        $properties['image_path'] = ['type' => ['string', 'null']];
        if ($kind === 'events') {
            $properties['timezone'] = ['type' => 'string'];
        }
        if ($kind === 'blocked_dates') {
            $properties['affected_booking_count'] = ['type' => 'integer', 'minimum' => 0];
        }

        return self::object($properties, array_keys($properties));
    }

    private static function definition(
        string $id,
        string $title,
        array $metadata,
        bool $write,
        array $properties,
        array $required,
        array $output,
        \Closure $handler,
        bool $destructive = false,
        bool $review = false,
        bool $demoAllowed = false,
        ?\Closure $preview = null
    ): OperationDefinition {
        return new OperationDefinition(
            operationId: $id,
            title: $title,
            description: $title
                . '. Uses the selected business, current role and venue timezone. Updates require the latest version returned by a read.',
            inputSchema: self::object($properties, $required),
            outputSchema: $output,
            requiredScopes: [$metadata['scope'] . ($write ? ':manage' : ':read')],
            actorPolicy: $write ? 'owner' : 'staff',
            readOnly: !$write,
            destructive: $destructive,
            confirmationPolicy: $review ? 'review' : 'none',
            patterns: $metadata['patterns'],
            documentationPage: 'catalog-and-schedule-tools',
            capabilityIds: [$metadata['capability']],
            acceptanceTestIds: array_values(array_unique([
                'A02',
                'A03',
                'A04',
                'A16',
                'A40',
                'A41',
                'A46',
                'A47',
                ...match ($metadata['capability']) {
                    'C12',
                    'C13' => ['A28', 'A33'],
                    'C14',
                    'C15' => ['A21', 'A29'],
                    'C16' => ['A22', 'A28', 'A33'],
                    'C17' => ['A23'],
                    default => ['A24', 'A33']
                }
            ])),
            httpMethod: $write ? 'POST' : 'GET',
            httpPath: '/operations/' . $id,
            handler: $handler,
            sideEffects: $write ? ['audit'] : [],
            errorCodes: [
                'not_found',
                'forbidden',
                'insufficient_scope',
                'validation_failed',
                'version_conflict',
                'conflict',
                'record_in_use',
                'invalid_cursor',
                'capacity_conflict'
            ],
            demoAllowed: !$write || $demoAllowed,
            previewHandler: $preview,
            // Every catalog write except create checks expected_version against a version that
            // covers the changed fields, so a repeat converges or meets version_conflict/not_found.
            idempotencyPolicy: $write && !str_ends_with($id, '_create') ? 'natural' : null,
        );
    }

    private static function object(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => $required
        ];
    }
}
