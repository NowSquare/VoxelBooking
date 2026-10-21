<?php

declare(strict_types=1);

namespace App\Controllers\AgentApi\V2;

use App\Agent\{OperationException, Operations, PublicTransport, SchemaValidator, TransportAuth};
use App\Agent\Mcp\ContractGenerator;
use App\Engine\{Request, Response};

final class PublicSessionController
{
    /**
     * The schema never depends on the server address, so a fixed base keeps contract lookup
     * independent of endpoint configuration that the session start itself does not need.
     */
    private const CONTRACT_BASE = 'https://booking.example/api/agent/v2';

    private static ?array $resultSchema = null;

    public function start(Request $request): Response
    {
        try {
            $session = PublicTransport::start($request);
            (new SchemaValidator())->validate(self::resultSchema(), $session, true);
            return TransportAuth::headers(Response::json($session), $request);
        } catch (OperationException $error) {
            return TransportAuth::problem($error, $request);
        }
    }

    /**
     * The public session result exactly as the OpenAPI document declares it.
     *
     * ContractGenerator owns the declaration. The MCP `public_session_start` tool and
     * this controller both read it from here, so the two transports cannot drift.
     */
    public static function resultSchema(): array
    {
        if (self::$resultSchema !== null) {
            return self::$resultSchema;
        }
        $public = array_values(array_filter(
            Operations::registry()->all(),
            static fn ($definition): bool => $definition->actorPolicy === 'public',
        ));
        $document = (new ContractGenerator())->openApi($public, self::CONTRACT_BASE, 'contract');
        foreach ($document['paths'] as $path => $operations) {
            if (str_ends_with($path, '/session') && isset($operations['post'])) {
                return self::$resultSchema = $operations['post']['responses']['200']['content']['application/json']['schema'];
            }
        }
        throw new \LogicException('The published contract declares no public session.');
    }
}
