<?php

declare(strict_types=1);

namespace App\Application\Customers;

use App\Agent\OperationDefinition;
use App\Application\Shared\Contract;

final class CustomerOperations
{
    public static function definitions(): array
    {
        $text = Contract::text(...);
        $item = Contract::object([
            'id' => $text(26),
            'name' => $text(),
            'email' => $text(),
            'phone' => $text(50, true),
            'booking_count' => ['type' => 'integer'],
            'last_booking_at' => $text(40, true),
            'created_at' => $text(40)
        ]);
        $detail = $item;
        $detail['properties']['notes'] = $text(65535, true);
        $detail['required'][] = 'notes';
        $page = static fn ($record) =>
            Contract::object([
                'items' => ['type' => 'array', 'items' => $record],
                'total' => ['type' => 'integer'],
                'next_cursor' => $text(4096, true)
            ]);
        $pagination = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => $text(4096)];
        $listInput = Contract::object([
            'business_id' => $text(26),
            'search' => $text(200),
            ...$pagination
        ], ['business_id']);
        $history = Contract::object([
            'id' => $text(26),
            'booking_pattern' => $text(20),
            'start_datetime' => $text(40),
            'end_datetime' => $text(40),
            'status' => $text(30),
            'service_name' => $text(255, true),
            'resource_name' => $text(255, true),
            'event_name' => $text(255, true)
        ]);
        $export = Contract::object([
            'filename' => $text(),
            'content_type' => $text(),
            'content' => ['type' => 'string'],
            'row_count' => ['type' => 'integer', 'maximum' => 1000],
            'next_cursor' => $text(4096, true)
        ]);
        $definitions = [];
        $specs = [
            ['customer_list', 'customers:read', '/customers', $listInput, $page($item), CustomerDirectory::search(...)],
            [
                'customer_get',
                'customers:read',
                '/customers/{customer_id}',
                Contract::object(['business_id' => $text(26), 'customer_id' => $text(26)]),
                $detail,
                static fn ($actor, $input) =>
                    CustomerDirectory::detail($actor, $input['business_id'], $input['customer_id'])
            ],
            [
                'customer_history',
                'customers:read',
                '/customers/{customer_id}/history',
                Contract::object([
                    'business_id' => $text(26),
                    'customer_id' => $text(26),
                    ...$pagination
                ], ['business_id', 'customer_id']),
                $page($history),
                CustomerDirectory::history(...)
            ],
            [
                'customer_export',
                'customers:export',
                '/customers/export',
                $listInput,
                $export,
                CustomerDirectory::export(...)
            ],
        ];
        foreach ($specs as [$name, $scope, $path, $input, $output, $handler]) {
            $definitions[] = new OperationDefinition(
                $name,
                __('agent.operations.' . $name),
                __('agent.descriptions.' . $name),
                $input,
                $output,
                [$scope],
                'staff',
                true,
                false,
                'none',
                ['timeslot', 'resource', 'capacity', 'event'],
                'customers-and-privacy',
                ['C11'],
                ['A28', 'A31'],
                'GET',
                '/businesses/{business_id}' . $path,
                $handler
            );
        }

        return $definitions;
    }
}
