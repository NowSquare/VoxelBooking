<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Read a customer review link the way the browser does.
 *
 * The link's fragment carries the review secret, which differs from the
 * agent's commit handle. Tests that stand in for the customer's browser
 * must take the secret from the link, never reuse the handle.
 */
final class ReviewLink
{
    public static function id(array $preview): string
    {
        return basename((string) parse_url($preview['review_url'], PHP_URL_PATH));
    }

    public static function secret(array $preview): string
    {
        parse_str((string) parse_url($preview['review_url'], PHP_URL_FRAGMENT), $fragment);

        return (string) ($fragment['review'] ?? '');
    }
}
