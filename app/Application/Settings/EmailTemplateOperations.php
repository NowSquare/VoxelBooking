<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Agent\OperationDefinition;
use App\Application\Shared\Contract as S;

final class EmailTemplateOperations
{
    public static function definitions(): array
    {
        $id = ['business_id' => S::text(26)];
        $type = ['type' => 'string', 'enum' => EmailTemplates::TYPES];
        $record = S::object(['type' => $type, ...EmailTemplates::fields()]);
        $output = S::object([...$id, 'templates' => ['type' => 'array', 'items' => $record], 'version' => S::text(64)]);
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];
        return [
            new OperationDefinition(
                'email_templates_get',
                __('agent.operations.email_templates_get'),
                __('agent.descriptions.email_templates_get'),
                S::object($id),
                $output,
                ['business-settings:read'],
                'owner',
                true,
                false,
                'none',
                $patterns,
                'settings-and-branding',
                ['C26'],
                ['A34'],
                'GET',
                '/businesses/{business_id}/settings/email-templates',
                static fn($a, $i) => EmailTemplates::read($a, $i['business_id']),
                errorCodes: ['forbidden', 'not_found']
            ),
            new OperationDefinition(
                'email_templates_update',
                __('agent.operations.email_templates_update'),
                __('agent.descriptions.email_templates_update'),
                S::object([
                    ...$id,
                    'expected_version' => S::text(64),
                    'templates' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 6,
                        'items' => S::object(['type' => $type, ...EmailTemplates::fields()], ['type'])
                    ]
                ]),
                $output,
                ['business-settings:manage'],
                'owner',
                false,
                false,
                'none',
                $patterns,
                'settings-and-branding',
                ['C26'],
                ['A34', 'A47'],
                'POST',
                '/businesses/{business_id}/settings/email-templates',
                EmailTemplates::update(...),
                sideEffects: ['audit'],
                errorCodes: ['demo_restricted', 'forbidden', 'not_found', 'version_conflict', 'validation_failed', 'audit_unavailable'],
                // Saving the same templates keeps the version; changed ones make a repeat meet version_conflict.
                idempotencyPolicy: 'natural'
            ),
            new OperationDefinition(
                'email_template_preview',
                __('agent.operations.email_template_preview'),
                __('agent.descriptions.email_template_preview'),
                S::object([...$id, 'type' => $type]),
                S::object([
                    'type' => $type,
                    'synthetic' => ['type' => 'boolean', 'const' => true],
                    'is_enabled' => ['type' => 'boolean'],
                    'content' => S::object(array_fill_keys(EmailTemplates::FIELDS, S::text(10000)))
                ]),
                ['business-settings:read'],
                'owner',
                true,
                false,
                'none',
                $patterns,
                'settings-and-branding',
                ['C26'],
                ['A34'],
                'GET',
                '/businesses/{business_id}/settings/email-templates/{type}/sample',
                EmailTemplates::preview(...),
                errorCodes: ['forbidden', 'not_found', 'validation_failed']
            ),
        ];
    }
}
