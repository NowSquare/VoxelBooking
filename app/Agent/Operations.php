<?php

declare(strict_types=1);

namespace App\Agent;

/** Explicit composition root shared by browser, HTTP and MCP adapters. */
final class Operations
{
    public static function registry(): OperationRegistry
    {
        return new OperationRegistry(
            ...\App\Application\Identity\IdentityOperations::definitions(),
            ...\App\Application\Settings\SettingsOperations::definitions(),
            ...\App\Application\Settings\EmailTemplateOperations::definitions(),
            ...\App\Application\Media\MediaOperations::definitions(),
            ...\App\Application\Catalog\CatalogOperations::definitions(),
            ...\App\Application\Customers\CustomerOperations::definitions(),
            ...\App\Application\Bookings\BookingOperations::definitions(),
            ...\App\Application\Availability\AvailabilityOperations::definitions(),
            ...\App\Application\Privacy\PrivacyOperations::definitions(),
            ...\App\Application\Privacy\DeletionOperations::definitions(),
            ...\App\Application\Business\BusinessOperations::definitions(),
            ...\App\Application\Integrations\WebhookOperations::definitions(),
            ...\App\Application\System\SystemOperations::definitions(),
        );
    }
}
