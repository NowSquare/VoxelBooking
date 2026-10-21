<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\Mcp;

use App\Agent\Mcp\ContractGenerator;
use App\Agent\OperationDefinition;
use PHPUnit\Framework\TestCase;

final class ContractGeneratorTest extends TestCase
{
    private function definition(bool $readOnly = true, string $confirmation = 'none'): OperationDefinition
    {
        return new OperationDefinition(
            'booking_get', 'Get booking', 'Read a permitted booking.',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'include_history' => ['type' => 'boolean']], 'required' => ['id'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []],
            ['bookings:read'], 'staff', $readOnly, false, $confirmation, ['timeslot', 'resource', 'capacity', 'event'],
            '/docs/agent-api/bookings', ['C05'], ['A01'], $readOnly ? 'GET' : 'POST', '/api/agent/v2/bookings/{id}',
            static fn (): array => [],
        );
    }

    public function testOpenApiUsesDefinitionAndPathParametersWithExactSchemas(): void
    {
        $document = (new ContractGenerator())->openApi([$this->definition()], 'https://booking.example', '2.0.0');
        self::assertSame('3.1.0', $document['openapi']);
        $operation = $document['paths']['/api/agent/v2/bookings/{id}']['get'];
        self::assertSame('booking_get', $operation['operationId']);
        self::assertSame('path', $operation['parameters'][0]['in']);
        self::assertTrue($operation['parameters'][0]['required']);
        self::assertSame('query', $operation['parameters'][1]['in']);
        self::assertSame(['type' => 'boolean'], $operation['parameters'][1]['schema']);
        self::assertSame(['bookings:read'], $operation['security'][0]['oauth']);
        self::assertSame('staff', $operation['x-actor-policy']);
        self::assertSame(['C05'], $operation['x-capability-ids']);
        self::assertInstanceOf(\stdClass::class, $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']);
    }

    public function testMutationIncludesIdempotencyAndExcludesPathFieldsFromBody(): void
    {
        $document = (new ContractGenerator())->openApi([$this->definition(false)], 'https://booking.example', '2.0.0');
        $operation = $document['paths']['/api/agent/v2/bookings/{id}']['post'];
        self::assertSame('Idempotency-Key', $operation['parameters'][1]['name']);
        self::assertTrue($operation['parameters'][1]['required']);
        $schema = $operation['requestBody']['content']['application/json']['schema'];
        self::assertArrayNotHasKey('id', $schema['properties']);
        self::assertSame([], $schema['required']);
        self::assertSame('#/components/schemas/Problem', $operation['responses']['409']['content']['application/problem+json']['schema']['$ref']);
    }

    public function testMcpToolsUseSameDefinitionsAndPreserveEmptyObjectMaps(): void
    {
        $tools = (new ContractGenerator())->tools([$this->definition()]);
        self::assertSame('booking_get', $tools[0]['name']);
        self::assertTrue($tools[0]['annotations']['readOnlyHint']);
        self::assertInstanceOf(\stdClass::class, $tools[0]['outputSchema']['properties']['data']['properties']);
        self::assertSame($this->definition()->inputSchema, $tools[0]['inputSchema']);
    }

    public function testReviewedCommandsExposePreviewAndCommitWithBoundHandle(): void
    {
        $generator = new ContractGenerator();
        $tools = $generator->tools([$this->definition(false, 'review')]);
        self::assertCount(2, $tools);
        self::assertSame(['preview_handle', 'idempotency_key'], $tools[0]['inputSchema']['required']);
        self::assertArrayNotHasKey('id', $tools[0]['inputSchema']['properties']);
        self::assertSame(8, $tools[0]['inputSchema']['properties']['idempotency_key']['minLength']);
        self::assertSame('booking_get_preview', $tools[1]['name']);
        self::assertTrue($tools[1]['annotations']['readOnlyHint']);
        self::assertSame($this->definition()->inputSchema, $tools[1]['inputSchema']);
        self::assertArrayHasKey('preview_handle', $tools[1]['outputSchema']['properties']);
        $schema = $generator->openApi([$this->definition(false, 'review')], 'https://booking.example', '2.0.0');
        $commit = $schema['paths']['/api/agent/v2/bookings/{id}']['post'];
        self::assertSame(['preview_handle'], $commit['requestBody']['content']['application/json']['schema']['required']);
        self::assertArrayHasKey('/api/agent/v2/bookings/{id}/preview', $schema['paths']);
        self::assertSame('https://voxelbooking.com/docs/agent-api/booking-tools', $commit['externalDocs']['url']);
    }

    public function testOAuthDiscoveryUsesIssuerOutsideVersionedResourcePath(): void
    {
        $schema = (new ContractGenerator())->openApi([], 'https://booking.example/api/agent/v2', '2.0.0');
        self::assertSame('https://booking.example/api/agent/v2', $schema['servers'][0]['url']);
        self::assertSame('https://booking.example/oauth/authorize', $schema['components']['securitySchemes']['oauth']['flows']['authorizationCode']['authorizationUrl']);
    }

    public function testPublicPathsHaveTransportSlugAndContinuationWithoutDomainInputPollution(): void
    {
        $definition = new OperationDefinition(
            'public_booking_create', 'Book an appointment', 'Create a booking for this public business.',
            ['type' => 'object', 'properties' => ['service_id' => ['type' => 'string']], 'required' => ['service_id'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []],
            ['public:book'], 'public', false, false, 'review', ['timeslot'], 'book-an-appointment', ['C01'], ['A01'],
            'POST', '/public/{slug}/bookings', static fn (): array => [],
        );
        $generator = new ContractGenerator();
        $document = $generator->openApi([$definition], 'https://booking.example/api/agent/v2', '2.0.0');
        $commit = $document['paths']['/public/{slug}/bookings']['post'];
        $preview = $document['paths']['/public/{slug}/bookings/preview']['post'];
        self::assertSame([], $commit['security']);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 100], $commit['parameters'][0]['schema']);
        self::assertSame('slug', $commit['parameters'][0]['name']);
        self::assertTrue($commit['parameters'][0]['required']);
        self::assertContains('X-Booking-Continuation', array_column($commit['parameters'], 'name'));
        self::assertContains('X-Booking-Continuation', array_column($preview['parameters'], 'name'));
        self::assertArrayNotHasKey('slug', $preview['requestBody']['content']['application/json']['schema']['properties']);
        self::assertArrayNotHasKey('slug', $generator->tools([$definition])[1]['inputSchema']['properties']);
        $session = $document['paths']['/public/{slug}/session']['post'];
        self::assertSame([], $session['security']);
        self::assertArrayHasKey('continuation', $session['responses']['200']['content']['application/json']['schema']['properties']);
        self::assertArrayNotHasKey('public:book', (array) $document['components']['securitySchemes']['oauth']['flows']['authorizationCode']['scopes']);
    }

    public function testVersionedServerDoesNotDuplicateLegacyPathPrefix(): void
    {
        $schema = (new ContractGenerator())->openApi([$this->definition()], 'https://booking.example/api/agent/v2', '2.0.0');
        self::assertArrayHasKey('/bookings/{id}', $schema['paths']);
        self::assertArrayNotHasKey('/api/agent/v2/bookings/{id}', $schema['paths']);
    }
}
