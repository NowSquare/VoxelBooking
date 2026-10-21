<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Agent\{ActorContext, OperationDefinition};

final class SettingsOperations
{
    public static function definitions(): array
    {
        $definitions = [];
        $capabilities = [
            'general' => 'C20',
            'branding' => 'C21',
            'bookingpage' => 'C22',
            'booking' => 'C23',
            'privacy' => 'C24',
            'notifications' => 'C25',
            'embed' => 'C27'
        ];
        $output = [
            'type' => 'object',
            'required' => ['business_id', 'section', 'values', 'version', 'url'],
            'properties' => [
                'business_id' => ['type' => 'string'],
                'section' => ['type' => 'string'],
                'values' => ['type' => 'object'],
                'version' => ['type' => 'string'],
                'url' => ['type' => 'string', 'format' => 'uri']
            ],
            'additionalProperties' => false
        ];
        foreach (SettingsFields::sections() as $section) {
            $valueFields = array_filter(
                SettingsFields::schema($section),
                static fn($field) => empty($field['writeOnly'])
            );
            if ($section === 'branding') {
                $valueFields['logo_url'] = ['type' => ['string', 'null'], 'format' => 'uri'];
            }

            if ($section === 'embed') {
                $valueFields['embed_snippet'] = ['type' => 'string'];
            }

            $requiredFields = array_keys($valueFields);
            if ($section === 'booking') {
                $requiredFields = array_values(array_diff(
                    $requiredFields,
                    ['slot_duration_minutes', 'buffer_minutes']
                ));
            }

            $output['properties']['section'] = ['type' => 'string', 'const' => $section];
            $output['properties']['values'] = [
                'type' => 'object',
                'properties' => $valueFields,
                'required' => $requiredFields,
                'additionalProperties' => false
            ];
            $patterns = $section === 'booking'
                ? ['timeslot', 'resource']
                : ['timeslot', 'resource', 'capacity', 'event'];
            $definitions[] = new OperationDefinition(
                'business_' . $section . '_get',
                __('agent.settings.' . $section),
                __('agent.settings.read_description'),
                SettingsFields::input($section, false),
                $output,
                ['business-settings:read'],
                'owner',
                true,
                false,
                'none',
                $patterns,
                'settings-and-branding',
                [$capabilities[$section]],
                ['A34'],
                'GET',
                '/businesses/{business_id}/settings/' . $section,
                static fn(ActorContext $actor, array $input) => SettingsService::read(
                    $actor,
                    $input['business_id'],
                    $section
                )
            );
            $definitions[] = new OperationDefinition(
                'business_' . $section . '_update',
                __('agent.settings.' . $section),
                __('agent.settings.update_description'),
                SettingsFields::input($section, true),
                $output,
                ['business-settings:manage'],
                'owner',
                false,
                false,
                in_array($section, ['general', 'privacy'], true) ? 'trusted' : 'none',
                $patterns,
                'settings-and-branding',
                [$capabilities[$section]],
                ['A16', 'A31', 'A34'],
                'POST',
                '/businesses/{business_id}/settings/' . $section,
                static fn(ActorContext $actor, array $input) => SettingsService::update($actor, $input, $section),
                previewHandler: static fn(ActorContext $actor, array $input) => SettingsService::preview(
                    $actor,
                    $input,
                    $section
                )
            );
        }

        return $definitions;
    }
}
