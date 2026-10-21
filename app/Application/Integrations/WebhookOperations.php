<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Agent\OperationDefinition;
use App\Application\Shared\Contract as S;

final class WebhookOperations
{
    public static function fields(): array
    {
        return [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'endpoint' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
            'event_types' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => WebhookService::EVENTS],
                'minItems' => 1,
                'maxItems' => 4,
                'uniqueItems' => true
            ],
            'is_active' => ['type' => 'boolean']
        ];
    }

    public static function definitions(): array
    {
        $business = ['business_id' => S::text(26)];
        $id = [...$business, 'subscription_id' => S::text(26)];
        $write = [...$id, 'expected_version' => S::text(64)];
        $record = S::object([
            'id' => S::text(26),
            ...$business,
            ...self::fields(),
            'version' => S::text(64),
            'secret_available' => ['type' => 'boolean'],
            'secret_browser_url' => S::text(2048),
            'created_at' => S::text(40),
            'updated_at' => S::text(40)
        ]);
        $definitions = [];
        $add = static function (
            string $name,
            array $input,
            array $output,
            bool $read,
            string $method,
            string $path,
            \Closure $handler,
            array $errors,
            ?\Closure $preview = null,
            array $effects = ['webhook_configuration'],
            ?string $idempotency = null
        ) use (&$definitions): void {
            $definitions[] = new OperationDefinition(
                $name,
                __('agent-webhooks.operations.' . $name),
                __('agent-webhooks.descriptions.' . $name),
                $input,
                $output,
                [$read ? 'webhooks:read' : 'webhooks:manage'],
                'owner',
                $read,
                false,
                $preview ? 'review' : 'none',
                ['timeslot', 'resource', 'capacity', 'event'],
                'webhooks-and-automation',
                ['C41'],
                ['A39', 'A41', 'A44'],
                $method,
                $path,
                $handler,
                $read ? [] : $effects,
                $errors,
                false,
                $preview,
                idempotencyPolicy: $idempotency
            );
        };
        $base = '/businesses/{business_id}/webhooks';
        $add(
            'webhooks_list',
            S::object($business),
            S::object(['items' => ['type' => 'array', 'items' => $record]]),
            true,
            'GET',
            $base,
            WebhookService::listing(...),
            ['forbidden', 'not_found', 'endpoint_configuration_invalid', 'https_required']
        );
        $add(
            'webhook_get',
            S::object($id),
            $record,
            true,
            'GET',
            $base . '/{subscription_id}',
            WebhookService::get(...),
            ['forbidden', 'not_found', 'endpoint_configuration_invalid', 'https_required']
        );
        $create = self::fields();
        unset($create['is_active']);
        $add(
            'webhook_create',
            S::object([...$business, ...$create]),
            $record,
            false,
            'POST',
            $base,
            WebhookService::create(...),
            [
                'demo_restricted',
                'forbidden',
                'not_found',
                'validation_failed',
                'subscription_limit',
                'endpoint_configuration_invalid',
                'https_required'
            ],
            WebhookService::preview(...)
        );
        $add(
            'webhook_update',
            S::object([...$write, ...self::fields()], array_keys($write)),
            $record,
            false,
            'POST',
            $base . '/{subscription_id}',
            WebhookService::update(...),
            [
                'demo_restricted',
                'forbidden',
                'not_found',
                'version_conflict',
                'validation_failed',
                'endpoint_configuration_invalid',
                'https_required'
            ],
            WebhookService::preview(...),
            idempotency: 'natural'
        );
        $add(
            'webhook_secret_rotate',
            S::object($write),
            $record,
            false,
            'POST',
            $base . '/{subscription_id}/rotate',
            WebhookService::rotate(...),
            [
                'demo_restricted',
                'forbidden',
                'not_found',
                'version_conflict',
                'endpoint_configuration_invalid',
                'https_required'
            ],
            WebhookService::preview(...)
        );
        $delivery = S::object([
            'id' => S::text(26),
            'event_id' => S::text(26),
            'event_type' => ['type' => 'string', 'enum' => WebhookService::EVENTS],
            'last_http_status' => ['type' => ['integer', 'null']],
            'last_error_code' => S::text(80, true),
            'last_attempt_at' => S::text(40, true),
            'created_at' => S::text(40),
            'manual_retries' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['pending', 'sending', 'failed', 'delivered']],
            'attempts' => ['type' => 'integer'],
            'available_at' => S::text(40)
        ]);
        $add(
            'webhook_deliveries_list',
            S::object([...$id, 'after_id' => S::text(26)], array_keys($id)),
            S::object(['items' => ['type' => 'array', 'items' => $delivery], 'next_after_id' => S::text(26, true)]),
            true,
            'GET',
            $base . '/{subscription_id}/deliveries',
            WebhookService::deliveries(...),
            ['forbidden', 'not_found']
        );
        $add(
            'webhook_delivery_retry',
            S::object([...$business, 'delivery_id' => S::text(26)]),
            S::object(['delivery_id' => S::text(26), 'status' => ['type' => 'string', 'const' => 'queued']]),
            false,
            'POST',
            '/businesses/{business_id}/webhook-deliveries/{delivery_id}/retry',
            WebhookService::retry(...),
            ['demo_restricted', 'forbidden', 'not_found', 'delivery_not_retryable'],
            effects: ['webhook_delivery']
        );
        return $definitions;
    }
}
