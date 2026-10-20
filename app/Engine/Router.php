<?php

declare(strict_types=1);

namespace App\Engine;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use App\Engine\Auth;
use App\Engine\Version;

/**
 * Thin wrapper around nikic/FastRoute.
 *
 * Registers route groups with middleware. Dispatches to controller methods.
 */
final class Router
{
    /** @var array<int, array{method: string, path: string, handler: array{0: string, 1: string}, middleware: string[]}> */
    private array $routes = [];

    /** @var string[] Current middleware stack for grouping */
    private array $currentMiddleware = [];

    public function get(string $path, string $controller, string $method): self
    {
        return $this->addRoute('GET', $path, $controller, $method);
    }

    public function post(string $path, string $controller, string $method): self
    {
        return $this->addRoute('POST', $path, $controller, $method);
    }

    public function put(string $path, string $controller, string $method): self
    {
        return $this->addRoute('PUT', $path, $controller, $method);
    }

    public function delete(string $path, string $controller, string $method): self
    {
        return $this->addRoute('DELETE', $path, $controller, $method);
    }

    /**
     * Group routes with shared middleware.
     */
    public function group(array $middleware, callable $callback): self
    {
        $previousMiddleware = $this->currentMiddleware;
        $this->currentMiddleware = array_merge($this->currentMiddleware, $middleware);

        $callback($this);

        $this->currentMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Dispatch the current request and return a Response.
     */
    public function dispatch(Request $request): Response
    {
        $dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->method(), $request->path());

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => $this->handleNotFound($request),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed(),
            Dispatcher::FOUND => $this->handleFound($request, $routeInfo[1], $routeInfo[2]),
        };
    }

    private function addRoute(string $method, string $path, string $controller, string $action): self
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'handler'    => [$controller, $action],
            'middleware'  => $this->currentMiddleware,
        ];

        return $this;
    }

    private function handleFound(Request $request, array $routeData, array $vars): Response
    {
        // Inject route parameters into request attributes
        foreach ($vars as $key => $value) {
            $request->setAttribute($key, $value);
        }

        // Run middleware pipeline
        $middlewareStack = $routeData['middleware'];
        $handler = $routeData['handler'];

        $next = function (Request $req) use ($handler): Response {
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass();

            return $controller->$method($req);
        };

        // Build middleware chain (innermost first)
        foreach (array_reverse($middlewareStack) as $middlewareClass) {
            $currentNext = $next;
            $next = function (Request $req) use ($middlewareClass, $currentNext): Response {
                $middleware = new $middlewareClass();

                return $middleware->handle($req, $currentNext);
            };
        }

        return $next($request);
    }

    private function handleNotFound(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json(['error' => 'not_found', 'message' => 'Not found'], 404);
        }

        // Admin routes render 404 inside the admin shell
        if (str_starts_with($request->path(), '/admin')) {
            try {
                Auth::startSession();
                return View::response('admin.errors.404', [
                    'user'      => Auth::user(),
                    'version'   => Version::get(),
                    'pageTitle' => __('admin.errors.404_admin_title'),
                    'csrfToken' => \App\Middleware\CsrfMiddleware::generateToken(),
                ], 404);
            } catch (\Throwable) {
                // Fall through to standalone
            }
        }

        try {
            return View::response('errors.404', [], 404);
        } catch (\Throwable) {
            return Response::html('<h1>404 ' . htmlspecialchars(__('admin.errors.404_title'), ENT_QUOTES, 'UTF-8') . '</h1>', 404);
        }
    }

    private function handleMethodNotAllowed(): Response
    {
        return Response::json(['error' => 'method_not_allowed', 'message' => 'Method not allowed'], 405);
    }
}
