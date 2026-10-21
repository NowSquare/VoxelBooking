<?php

declare(strict_types=1);

namespace App\Controllers\AgentApi;

use App\Engine\AgentAuth;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Agent API: Schema endpoint.
 *
 * GET /api/agent/v1/schema → OpenAPI 3.0 specification (no auth required)
 *
 * This endpoint is public to support LLM tool-calling integrations
 * that need to discover available operations and their parameters.
 */
final class SchemaController
{
    public function index(Request $request): Response
    {
        $baseUrl = app_url();
        $appName = app_name();

        $schema = [
            'openapi' => '3.0.3',
            'info' => [
                'title'       => $appName . ' Agent API',
                'version'     => '1.0.0',
                'description' => 'Read-only API for external agents and integrations. Authenticate with Bearer token.',
            ],
            'servers' => [
                ['url' => $baseUrl . '/api/agent/v1'],
            ],
            'security' => [
                ['bearerAuth' => []],
            ],
            'paths' => [
                '/tenants' => [
                    'get' => [
                        'summary'     => 'List tenants',
                        'operationId' => 'listTenants',
                        'tags'        => ['Tenants'],
                        'x-required-scope' => 'tenants:read',
                        'responses'   => [
                            '200' => [
                                'description' => 'List of tenants',
                                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TenantList']]],
                            ],
                        ],
                    ],
                ],
                '/bookings' => [
                    'get' => [
                        'summary'     => 'List bookings',
                        'operationId' => 'listBookings',
                        'tags'        => ['Bookings'],
                        'x-required-scope' => 'bookings:read',
                        'parameters'  => [
                            ['name' => 'tenant_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                            [
                                'name' => 'status',
                                'in' => 'query',
                                'required' => false,
                                'schema' => [
                                    'type' => 'string',
                                    'enum' => ['pending', 'confirmed', 'waitlisted', 'completed', 'cancelled', 'no_show', 'rescheduled'],
                                ],
                            ],
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                            ],
                            [
                                'name' => 'offset',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'maximum' => 100000],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'List of bookings',
                                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/BookingList']]],
                            ],
                        ],
                    ],
                ],
                '/services' => [
                    'get' => [
                        'summary'     => 'List services for a tenant',
                        'operationId' => 'listServices',
                        'tags'        => ['Services'],
                        'x-required-scope' => 'services:read',
                        'parameters'  => [
                            ['name' => 'tenant_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'List of services',
                                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ServiceList']]],
                            ],
                        ],
                    ],
                ],
                '/availability' => [
                    'get' => [
                        'summary'     => 'Get weekly schedule configuration for a tenant',
                        'description' => 'Returns at most 100 weekly rules, not computed bookable appointments. Legacy date arguments have no effect. Use v2 availability operations for bookable choices.',
                        'operationId' => 'getAvailability',
                        'tags'        => ['Availability'],
                        'x-required-scope' => 'availability:read',
                        'parameters'  => [
                            ['name' => 'tenant_id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Availability schedule',
                                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AvailabilityList']]],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'   => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
                'schemas' => [
                    'TenantList' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tenant']],
                        ],
                    ],
                    'Tenant' => [
                        'type' => 'object',
                        'properties' => [
                            'id'       => ['type' => 'string'],
                            'name'     => ['type' => 'string'],
                            'slug'     => ['type' => 'string'],
                            'status'   => ['type' => 'string'],
                            'timezone' => ['type' => 'string'],
                            'currency' => ['type' => 'string'],
                            'locale' => ['type' => 'string'],
                            'booking_pattern' => ['type' => 'string', 'enum' => ['timeslot', 'resource', 'capacity', 'event']],
                            'created_at' => ['type' => 'string', 'description' => 'Stored application wall datetime, YYYY-MM-DD HH:MM:SS.'],
                        ],
                    ],
                    'BookingList' => [
                        'type' => 'object',
                        'properties' => [
                            'data'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Booking']],
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                    'Booking' => [
                        'type' => 'object',
                        'properties' => [
                            'id'             => ['type' => 'string'],
                            'tenant_id'      => ['type' => 'string'],
                            'service_id'     => ['type' => 'string', 'nullable' => true],
                            'staff_id'       => ['type' => 'string', 'nullable' => true],
                            'customer_id'    => ['type' => 'string'],
                            'start_datetime' => ['type' => 'string', 'description' => 'Venue-local wall datetime, YYYY-MM-DD HH:MM:SS.'],
                            'end_datetime'   => ['type' => 'string', 'description' => 'Venue-local wall datetime, YYYY-MM-DD HH:MM:SS.'],
                            'status'         => ['type' => 'string'],
                            'source'         => ['type' => 'string'],
                            'has_consent'    => ['type' => 'boolean'],
                            'created_at'     => ['type' => 'string', 'description' => 'Stored application wall datetime, YYYY-MM-DD HH:MM:SS.'],
                        ],
                    ],
                    'ServiceList' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Service']],
                        ],
                    ],
                    'Service' => [
                        'type' => 'object',
                        'properties' => [
                            'id'               => ['type' => 'string'],
                            'name'             => ['type' => 'string'],
                            'description'      => ['type' => 'string', 'nullable' => true],
                            'duration_minutes' => ['type' => 'integer'],
                            'price'            => ['type' => 'string', 'nullable' => true, 'pattern' => '^[0-9]+\\.[0-9]{2}$'],
                            'color'            => ['type' => 'string', 'nullable' => true],
                            'sort_order'       => ['type' => 'integer'],
                            'is_active'        => ['type' => 'boolean'],
                        ],
                    ],
                    'AvailabilityList' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Availability']],
                            'kind' => ['type' => 'string', 'enum' => ['weekly_schedule']],
                        ],
                    ],
                    'Availability' => [
                        'type' => 'object',
                        'properties' => [
                            'staff_id'    => ['type' => 'string', 'nullable' => true],
                            'day_of_week' => ['type' => 'integer'],
                            'start_time'  => ['type' => 'string'],
                            'end_time'    => ['type' => 'string'],
                            'is_available' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];

        return Response::json($schema);
    }
}
