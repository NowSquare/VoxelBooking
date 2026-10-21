<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use InvalidArgumentException;

final class ClientMetadata
{
    public static function validate(array $metadata, ?string $documentUrl = null): array
    {
        $name = $metadata['client_name'] ?? null;
        $redirects = $metadata['redirect_uris'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 120
            || !is_array($redirects) || !array_is_list($redirects)
            || count($redirects) < 1 || count($redirects) > 10) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        foreach ($redirects as $redirect) {
            if (!is_string($redirect) || strlen($redirect) > 2048 || !self::redirectAllowed($redirect)) {
                throw new InvalidArgumentException('invalid_redirect_uri');
            }
        }
        $grants = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        $responses = $metadata['response_types'] ?? ['code'];
        $auth = $metadata['token_endpoint_auth_method'] ?? 'none';
        if (!is_array($grants) || !array_is_list($grants) || !in_array('authorization_code', $grants, true)
            || array_diff($grants, ['authorization_code', 'refresh_token']) !== []
            || $responses !== ['code']
            || !in_array($auth, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        // A metadata document must describe itself and cannot claim a client secret.
        if ($documentUrl !== null && (($metadata['client_id'] ?? null) !== $documentUrl || $auth !== 'none')) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        $result = [
            'client_name' => trim($name),
            'redirect_uris' => array_values(array_unique($redirects)),
            'grant_types' => array_values(array_unique($grants)),
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $auth,
        ];
        if ($documentUrl !== null) {
            $result['client_id'] = $documentUrl;
        }
        return $result;
    }

    private static function redirectAllowed(string $url): bool
    {
        if (!preg_match('/^[\x21-\x7E]+$/D', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['fragment'], $parts['user'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || empty($parts['host'])) {
            return false;
        }
        // Plain HTTP is allowed only for loopback redirects used by native and CLI clients.
        return ($parts['scheme'] ?? '') === 'https'
            || (($parts['scheme'] ?? '') === 'http'
                && in_array(strtolower($parts['host']), ['127.0.0.1', '[::1]', 'localhost'], true));
    }
}
