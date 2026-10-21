<?php

declare(strict_types=1);

namespace App\Controllers\OAuth;

use App\Agent\ConnectionService;
use App\Agent\ActorContext;
use App\Agent\Endpoints;
use App\Agent\OAuth\Factory;
use App\Agent\OAuth\RegistrationThrottled;
use App\Agent\OperationException;
use App\Agent\Operations;
use App\Agent\RateBudget;
use App\Agent\SessionActor;
use App\Engine\Auth;
use App\Engine\CustomerCapability;
use App\Engine\Database;
use App\Engine\DemoMode;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;

final class OAuthController
{
    public function authorize(Request $request): Response
    {
        return $this->guard($request, 'authorize', function () use ($request): Response {
            Auth::startSession();
            $pending = &$_SESSION['oauth_pending'];
            // Drop expired authorization requests before looking up this one.
            $pending = is_array($pending)
                ? array_filter($pending, static fn ($entry) => is_array($entry) && ($entry['expires_at'] ?? 0) > time())
                : [];
            $handle = $request->query('request');
            if ($handle === '') {
                $query = $this->queryParameters();
                $factory = new Factory(Operations::registry());
                $factory->registration->ensure($query['client_id'] ?? '');
                $factory->authorization->validateAuthorization($query);
                $this->customerSelector($query);
                if (count($pending) >= 10) {
                    array_shift($pending);
                }
                $handle = bin2hex(random_bytes(32));
                $pending[$handle] = ['query' => $query, 'expires_at' => time() + 600];
            }
            if (!preg_match('/^[a-f0-9]{64}$/D', $handle) || !isset($pending[$handle])) {
                return $this->browserError('expired');
            }
            $selector = $this->customerSelector($pending[$handle]['query']);
            $customer = $selector === null ? null : CustomerCapability::actor($request, ...$selector);
            if ($selector !== null && $customer === null) {
                return $this->customerHandoff($handle, $selector);
            }
            if ($selector === null && !Auth::check()) {
                $_SESSION['oauth_return_to'] = '/oauth/authorize?request=' . $handle;
                return Response::redirect('/admin/login');
            }
            $registry = Operations::registry();
            $actor = $customer ?? SessionActor::resolve($registry);
            if ($this->mustChangePassword($actor->principalType, $actor->principalId)) {
                return $this->browserError('password_required');
            }
            $query = $pending[$handle]['query'];
            $factory = new Factory($registry);
            $client = $factory->registration->ensure($query['client_id']);
            $authorization = $factory->authorization->validateAuthorization($query);
            $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $authorization->getScopes());
            $available = [];
            $titles = [];
            foreach ($registry->visible($actor) as $operation) {
                $available = [...$available, ...$operation->requiredScopes];
                if ($operation->requiredScopes !== [] && array_diff($operation->requiredScopes, $scopes) === []) {
                    $titles[] = $operation->title;
                }
            }
            if (array_diff($scopes, $available) !== []) {
                return $this->browserError('permission_denied');
            }
            $pending[$handle]['principal'] = $this->principalBinding($actor);
            $businesses = [];
            if ($actor->businessIds !== []) {
                $businesses = Database::query(
                    'SELECT id, name FROM tenants WHERE id IN ('
                        . implode(',', array_fill(0, count($actor->businessIds), '?'))
                        . ') ORDER BY name, id',
                    $actor->businessIds,
                );
            }
            Locale::resolveForAdmin(null);
            $user = $customer === null
                ? Auth::user()
                : Database::query(
                    'SELECT name, email FROM customers WHERE id = ? AND tenant_id = ?',
                    [$actor->principalId, $actor->business()],
                )[0];
            $expiresAt = $customer === null ? gmdate('c', time() + 90 * 86400) : gmdate('c', $this->customerExpiry($actor));
            return View::response('admin.connections.oauth-consent', [
                'user' => $user, 'version' => Version::get(), 'pageTitle' => __('oauth.title'),
                'csrfToken' => CsrfMiddleware::generateToken(), 'handle' => $handle,
                'clientName' => $client->getName(), 'clientId' => $client->getIdentifier(),
                'clientNameVerified' => $client->hasVerifiedName(),
                'redirectHost' => self::redirectHost($query['redirect_uri'] ?? ((array) $client->getRedirectUri())[0] ?? ''),
                'scopes' => $scopes, 'operationTitles' => array_values(array_unique($titles)), 'businesses' => $businesses,
                'isCustomer' => $customer !== null, 'customerPurpose' => $selector[0] ?? null, 'expiresAt' => $expiresAt,
                'formError' => $pending[$handle]['error'] ?? null,
            ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        });
    }

    /**
     * Where the browser returns after consent, the one detail a client cannot
     * disguise. Loopback hosts belong to an app on the person's own computer.
     *
     * @return array{host: string, local: bool}
     */
    private static function redirectHost(string $redirectUri): array
    {
        $host = strtolower((string) parse_url($redirectUri, PHP_URL_HOST));
        $host = trim($host, '[]');

        return [
            'host' => $host,
            'local' => in_array($host, ['127.0.0.1', '::1', 'localhost'], true),
        ];
    }

    public function approve(Request $request): Response
    {
        return $this->guard($request, 'authorize', function () use ($request): Response {
            Auth::startSession();
            if (!CsrfMiddleware::tokenMatches($request)) {
                return $this->browserError('expired');
            }
            $handle = $request->string('request');
            $pending = $_SESSION['oauth_pending'][$handle] ?? null;
            if (!preg_match('/^[a-f0-9]{64}$/D', $handle) || !is_array($pending) || $pending['expires_at'] <= time()) {
                return $this->browserError('expired');
            }
            $registry = Operations::registry();
            $selector = $this->customerSelector($pending['query']);
            $actor = $selector === null
                ? (Auth::check() ? SessionActor::resolve($registry) : null)
                : CustomerCapability::actor($request, ...$selector);
            if ($actor === null || ($pending['principal'] ?? null) !== $this->principalBinding($actor)
                || $this->mustChangePassword($actor->principalType, $actor->principalId)) {
                return $this->browserError('permission_denied');
            }
            if (DemoMode::isActive()) {
                return $this->browserError('demo');
            }
            $factory = new Factory($registry);
            $query = $pending['query'];
            $client = $factory->registration->ensure($query['client_id']);
            $authorization = $factory->authorization->validateAuthorization($query);
            if ($request->string('decision') !== 'approve') {
                unset($_SESSION['oauth_pending'][$handle]);
                return $this->convert($factory->authorization->authorize($query, '', false));
            }
            $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $authorization->getScopes());
            $businesses = $actor->principalType === 'customer' ? $actor->businessIds : ($request->all()['business_ids'] ?? []);
            if (!is_array($businesses) || !array_is_list($businesses)
                || array_filter($businesses, static fn ($id) => !is_string($id)) !== []
                || $businesses === []) {
                $_SESSION['oauth_pending'][$handle]['error'] = 'choose_business';
                return Response::redirect('/oauth/authorize?request=' . $handle);
            }
            $response = Database::transaction(function () use (
                $registry,
                $actor,
                $client,
                $scopes,
                $businesses,
                $query,
                $factory,
            ): ResponseInterface {
                $service = new ConnectionService($registry);
                $connection = $actor->principalType === 'customer'
                    ? $service->createCustomerGrant(
                        $actor,
                        $client->getName(),
                        $scopes,
                        gmdate('Y-m-d H:i:s', $this->customerExpiry($actor)),
                        __('oauth.connection_purpose'),
                        $client->getIdentifier(),
                    )
                    : $service->createGrant(
                        $actor,
                        $client->getName(),
                        $scopes,
                        $businesses,
                        gmdate('Y-m-d H:i:s', time() + 90 * 86400),
                        __('oauth.connection_purpose'),
                        $client->getIdentifier(),
                    );
                Database::execute(
                    'INSERT INTO oauth_grants (connection_id, audience) VALUES (?, ?)',
                    [$connection['id'], $query['resource']],
                );
                return $factory->authorization->authorize($query, $connection['id'], true);
            });
            unset($_SESSION['oauth_pending'][$handle]);
            return $this->convert($response);
        });
    }

    public function token(Request $request): Response
    {
        return $this->guard($request, 'token', function () use ($request): Response {
            $parameters = $this->formParameters();
            $factory = new Factory(Operations::registry());
            $clientId = $parameters['client_id'] ?? $this->basicClient($request->header('Authorization'));
            $factory->registration->ensure($clientId);
            return $this->convert($factory->authorization->token($parameters, $request->header('Authorization')));
        });
    }

    public function register(Request $request): Response
    {
        return $this->guard($request, 'register', function () use ($request): Response {
            if (DemoMode::isActive()) {
                return $this->jsonError('access_denied', 403);
            }
            $raw = file_get_contents('php://input', length: 65537);
            if ($raw === false || strlen($raw) > 65536) {
                return $this->jsonError('invalid_client_metadata', 400);
            }
            $metadata = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata) || array_is_list($metadata)) {
                return $this->jsonError('invalid_client_metadata', 400);
            }
            $factory = new Factory(Operations::registry());
            return Response::json($factory->registration->register($metadata, 'dynamic', $request->ip()), 201)
                ->header('Cache-Control', 'no-store');
        });
    }

    public function revoke(Request $request): Response
    {
        return $this->guard($request, 'token', function () use ($request): Response {
            $parameters = $this->formParameters();
            $factory = new Factory(Operations::registry());
            $factory->registration->ensure($parameters['client_id'] ?? $this->basicClient($request->header('Authorization')));
            return $this->convert($factory->authorization->revoke($parameters, $request->header('Authorization')));
        });
    }

    public function metadata(Request $request): Response
    {
        return $this->guard(
            $request,
            'metadata',
            fn (): Response => Response::json((new Factory(Operations::registry()))->authorization->metadata())
                ->header('Cache-Control', 'no-store'),
        );
    }

    public function resourceMetadata(Request $request): Response
    {
        $suffix = str_ends_with($request->path(), '/api/agent/v2') ? '/api/agent/v2' : '/mcp';
        return $this->guard(
            $request,
            'metadata',
            fn (): Response => Response::json(
                (new Factory(Operations::registry()))->authorization->resourceMetadata(Endpoints::baseUrl() . $suffix)
            )->header('Cache-Control', 'no-store'),
        );
    }

    private function guard(Request $request, string $group, \Closure $handler): Response
    {
        try {
            $limit = $group === 'register' ? 10 : ($group === 'metadata' ? 120 : 60);
            $budget = (new RateBudget())->consume(['oauth:' . $group . ':' . $request->ip() => $limit]);
            if (!$budget['allowed']) {
                return $this->jsonError('slow_down', 429)->header('Retry-After', (string) $budget['retry_after']);
            }
            return $handler();
        } catch (OAuthServerException $error) {
            if ($group === 'authorize') {
                return $this->browserError($error->getErrorType() === 'access_denied' ? 'denied' : 'invalid_request');
            }
            return $this->convert($error->generateHttpResponse(new \Nyholm\Psr7\Response()));
        } catch (RegistrationThrottled $throttled) {
            if ($group === 'authorize') {
                return $this->browserError('unavailable');
            }
            return $this->jsonError('slow_down', 429)->header('Retry-After', (string) $throttled->retryAfter);
        } catch (\InvalidArgumentException|\JsonException $error) {
            return $group === 'authorize' ? $this->browserError('invalid_request') : $this->jsonError('invalid_request', 400);
        } catch (OperationException $error) {
            return $group === 'authorize' ? $this->browserError('permission_denied') : $this->jsonError('invalid_grant', 400);
        } catch (\Throwable) {
            return $group === 'authorize' ? $this->browserError('unavailable') : $this->jsonError('temporarily_unavailable', 503);
        }
    }

    private function browserError(string $reason): Response
    {
        Auth::startSession();
        Locale::resolveForAdmin(null);
        return View::response('admin.connections.oauth-error', [
            'pageTitle' => __('oauth.error_title'), 'reason' => $reason,
            'user' => Auth::user(), 'version' => Version::get(),
        ], 400)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function jsonError(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status)->header('Cache-Control', 'no-store');
    }

    private function convert(ResponseInterface $source): Response
    {
        $response = (new Response())->status($source->getStatusCode())->body((string) $source->getBody());
        foreach ($source->getHeaders() as $name => $values) {
            $response->header($name, implode(', ', $values));
        }
        return $response->header('Cache-Control', 'no-store');
    }

    private function formParameters(): array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type !== 'application/x-www-form-urlencoded') {
            throw OAuthServerException::invalidRequest('content_type');
        }
        return $this->parseParameters(file_get_contents('php://input', length: 32769) ?: '');
    }

    private function queryParameters(): array
    {
        return $this->parseParameters((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY));
    }

    private function parseParameters(string $raw): array
    {
        if (strlen($raw) > 32768) {
            throw OAuthServerException::invalidRequest('parameters');
        }
        $result = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name) || array_key_exists($name, $result)) {
                throw OAuthServerException::invalidRequest('parameters');
            }
            $result[$name] = urldecode($value);
        }
        return $result;
    }

    private function basicClient(?string $authorization): string
    {
        if ($authorization === null || stripos($authorization, 'Basic ') !== 0) {
            return '';
        }
        $decoded = base64_decode(substr($authorization, 6), true);
        return $decoded === false ? '' : urldecode(explode(':', $decoded, 2)[0]);
    }

    private function customerSelector(array $query): ?array
    {
        if (!isset($query['customer_purpose'], $query['business_id'], $query['subject_id'])) {
            if (isset($query['customer_purpose']) || isset($query['subject_id'])) {
                throw OAuthServerException::invalidRequest('customer_purpose');
            }
            return null;
        }
        if (!in_array($query['customer_purpose'], [CustomerCapability::MANAGE, CustomerCapability::PRIVACY], true)
            || !preg_match('/^[A-Za-z0-9_-]{1,26}$/D', $query['business_id'])
            || !preg_match('/^[A-Za-z0-9_-]{1,26}$/D', $query['subject_id'])) {
            throw OAuthServerException::invalidRequest('customer_purpose');
        }
        return [$query['customer_purpose'], $query['business_id'], $query['subject_id']];
    }

    private function principalBinding(ActorContext $actor): string
    {
        return $actor->principalType . ':' . $actor->principalId . ($actor->principalType === 'customer' ? ':' . $actor->credentialId : '');
    }

    private function customerExpiry(ActorContext $actor): int
    {
        $row = Database::query(
            'SELECT expires_at FROM customer_capabilities WHERE id = ? AND revoked_at IS NULL',
            [$actor->credentialId],
        )[0] ?? null;
        $expiry = $row === null ? false : strtotime($row['expires_at'] . ' UTC');
        if ($expiry === false || $expiry <= time()) {
            throw new OperationException('unauthorized', 401);
        }
        return min(time() + 90 * 86400, $expiry);
    }

    private function customerHandoff(string $handle, array $selector): Response
    {
        [$purpose, $tenantId, $subjectId] = $selector;
        $tenant = Database::query("SELECT slug FROM tenants WHERE id = ? AND status = 'active'", [$tenantId])[0] ?? null;
        if ($tenant === null) {
            return $this->browserError('invalid_request');
        }
        $_SESSION['oauth_customer_return'] = [
            'handle' => $handle,
            'purpose' => $purpose,
            'tenant_id' => $tenantId,
            'subject_id' => $subjectId,
            'expires_at' => $_SESSION['oauth_pending'][$handle]['expires_at'],
        ];
        Locale::resolveForAdmin(null);
        return View::response('admin.connections.oauth-customer-handoff', [
            'pageTitle' => __('oauth.verify_title'), 'handle' => $handle,
            'verificationUrl' => CustomerCapability::link($tenant['slug'], $purpose, $subjectId),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function mustChangePassword(string $type, string $id): bool
    {
        if ($type !== 'business_user') {
            return false;
        }
        return !empty(Database::query('SELECT force_password_change FROM business_users WHERE id = ?', [$id])[0]['force_password_change']);
    }
}
