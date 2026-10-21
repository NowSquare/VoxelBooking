<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * What the adapter serving this request negotiated with the client.
 *
 * Operations receive only the actor and their input, and should stay that way.
 * connection_test is the one operation whose answer is about the transport
 * itself, so the MCP adapter records the protocol revision here for the length
 * of a single tool call.
 */
final class TransportContext
{
    private static ?string $protocolVersion = null;

    /** Run one call with the negotiated protocol revision visible, then restore the previous state. */
    public static function during(?string $protocolVersion, callable $call): mixed
    {
        $previous = self::$protocolVersion;
        self::$protocolVersion = $protocolVersion;
        try {
            return $call();
        } finally {
            self::$protocolVersion = $previous;
        }
    }

    /** The MCP protocol revision of the current call, or null outside MCP. */
    public static function protocolVersion(): ?string
    {
        return self::$protocolVersion;
    }
}
