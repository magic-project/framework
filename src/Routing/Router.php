<?php

declare(strict_types=1);

namespace Magic\Routing;

use InvalidArgumentException;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Magic\Container\Container;
use Magic\Context\ContextManager;
use Magic\Http\ErrorResponse;
use Magic\Http\Middleware\MiddlewareStack;
use Magic\Http\Middleware\MiddlewareRegistry;
use Magic\Http\Request;
use Magic\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;

final class Router
{
    private const string PATH_METHOD = '__MAGIC_PATH__';

    /**
     * @var list<array{
     *     method: string,
     *     pattern: string,
     *     shape: string,
     *     handler: callable|array{class-string, string}|class-string,
     *     specificity: list<int>,
     *     literalLength: int,
     *     order: int,
     *     middleware: list<PsrMiddlewareInterface>,
     * }>
     */
    private array $routes = [];

    /** @var array<int, array<string, mixed>> */
    private array $routesByOrder = [];

    /** @var array<string, list<string>> */
    private array $allowedMethodsByShape = [];

    private ?Dispatcher $dispatcher = null;

    private int $nextOrder = 0;

    private bool $frozen = false;

    public function __construct(
        private readonly ?Container $container = null,
        private readonly ?ContextManager $context = null,
        private readonly ?MiddlewareRegistry $middlewareRegistry = null,
    )
    {
    }

    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        $this->rebuildDispatcher();
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @param string|list<string> $methods
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function add(string|array $methods, string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        if ($this->frozen) {
            throw new \RuntimeException('Cannot register routes after application boot.');
        }

        $this->validateHandler($handler);

        $normalizedMethods = [];
        foreach ((array) $methods as $method) {
            $normalizedMethod = $this->normalizeMethod($method);
            $normalizedMethods[$normalizedMethod] = true;
        }

        if ($normalizedMethods === []) {
            throw new InvalidArgumentException('At least one HTTP method is required.');
        }

        $compiled = $this->compilePattern($pattern);
        $resolvedMiddleware = $this->resolveMiddleware($middleware);
        $methodsToAdd = array_keys($normalizedMethods);

        foreach ($methodsToAdd as $method) {
            foreach ($this->routes as $route) {
                if ($route['method'] === $method && $route['shape'] === $compiled['shape']) {
                    throw new InvalidArgumentException(sprintf(
                        'A route for method [%s] and pattern [%s] is already registered.',
                        $method,
                        $pattern,
                    ));
                }
            }
        }

        foreach ($methodsToAdd as $method) {
            $route = [
                'method' => $method,
                'pattern' => $compiled['pattern'],
                'shape' => $compiled['shape'],
                'handler' => $handler,
                'specificity' => $compiled['specificity'],
                'literalLength' => $compiled['literalLength'],
                'order' => $this->nextOrder++,
                'middleware' => $resolvedMiddleware,
            ];
            $this->routes[] = $route;
            $this->routesByOrder[$route['order']] = $route;
        }

        usort($this->routes, $this->compareRoutes(...));
        $this->dispatcher = null;
    }

    /**
     * @param list<string> $methods
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function map(array $methods, string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add($methods, $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function get(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function post(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function put(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function patch(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function delete(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function options(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('OPTIONS', $pattern, $handler, $middleware);
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param list<mixed> $middleware
     */
    public function head(string $pattern, callable|array|string $handler, array $middleware = []): void
    {
        $this->add('HEAD', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        if ($this->dispatcher === null) {
            $this->rebuildDispatcher();
        }

        try {
            $path = $this->normalizePath($request->path());
        } catch (InvalidArgumentException) {
            return ErrorResponse::json('bad_request', 'Invalid URI path', 400);
        }
        $pathResult = $this->dispatcher?->dispatch(self::PATH_METHOD, $path);
        if (!is_array($pathResult) || $pathResult[0] !== Dispatcher::FOUND) {
            return ErrorResponse::json('not_found', 'Not Found', 404);
        }

        // Select the most specific path shape before negotiating the method.
        // A static route should claim its path even when a less-specific route
        // would support the requested method.
        $pathRoute = $this->routeByOrder((int) $pathResult[1]);
        if ($pathRoute === null) {
            return ErrorResponse::json('not_found', 'Not Found', 404);
        }
        $bestShape = $pathRoute['shape'];

        $allowedMethods = $this->allowedMethodsByShape[$bestShape] ?? [];

        $result = $this->dispatcher?->dispatch($request->method(), $path);
        if (is_array($result) && $result[0] === Dispatcher::FOUND) {
            $route = $this->routeByOrder((int) $result[1]);
            if ($route !== null && $route['shape'] === $bestShape) {
                return $this->invoke($route, $request, $result[2], $request->method() === 'HEAD');
            }
        }

        if ($request->method() === 'HEAD' && in_array('GET', $allowedMethods, true)) {
            $headResult = $this->dispatcher?->dispatch('GET', $path);
            if (is_array($headResult) && $headResult[0] === Dispatcher::FOUND) {
                $route = $this->routeByOrder((int) $headResult[1]);
                if ($route !== null && $route['shape'] === $bestShape) {
                    return $this->invoke($route, $request, $headResult[2], true);
                }
            }
        }

        if ($request->method() === 'OPTIONS') {
            return new Response('', 204, ['Allow' => implode(', ', $allowedMethods)]);
        }

        return ErrorResponse::json(
            'method_not_allowed',
            'Method Not Allowed',
            405,
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }

    /**
     * @param array{handler: callable|array{class-string, string}|class-string, middleware: list<PsrMiddlewareInterface>} $route
     * @param array<int|string, string> $params
     */
    private function invoke(array $route, Request $request, array $params, bool $head): Response
    {
        // FastRoute matched against the raw path, so decode the captured values
        // here so handlers receive the real parameter (e.g. %2F becomes '/').
        $params = array_map(static fn (string $value): string => rawurldecode($value), $params);
        $request = Request::fromPsr($request->withAttribute('route_params', $params));
        if ($this->context?->isActive()) {
            $this->context->current()->replaceRequest($request);
        }

        $terminal = function (ServerRequestInterface $nextRequest) use ($route, $params): ResponseInterface {
            $nextRequest = Request::fromPsr($nextRequest);
            $result = $this->invokeHandler($route['handler'], $nextRequest, $params);

            return match (true) {
                $result instanceof ResponseInterface => $result,
                is_array($result) => Response::json($result),
                default => new Response((string) $result),
            };
        };

        $response = $route['middleware'] === []
            ? $terminal($request)
            : (new MiddlewareStack($route['middleware'], $terminal))->handle($request);
        $response = $this->toResponse($response);

        if ($head) {
            return new Response('', $response->status(), $response->headers());
        }

        return $response;
    }

    private function toResponse(ResponseInterface $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $values;
        }

        return new Response(
            (string) $response->getBody(),
            $response->getStatusCode(),
            $headers,
            $response->getReasonPhrase(),
        );
    }

    /**
     * @param callable|array{class-string, string}|class-string $handler
     * @param array<int|string, string> $params
     */
    private function invokeHandler(callable|array|string $handler, Request $request, array $params): mixed
    {
        if (is_callable($handler)) {
            return $handler($request, $params);
        }

        if ($this->container === null) {
            throw new \RuntimeException('Controller routes require a Router created with a service container.');
        }

        if (is_string($handler)) {
            $controller = $this->container->resolveController($handler);
            if (!is_callable($controller)) {
                throw new \RuntimeException(sprintf('Action class [%s] must implement __invoke().', $handler));
            }

            return $this->invokeMethod($controller, '__invoke', $request, $params);
        }

        [$class, $method] = $handler;
        $controller = $this->container->resolveController($class);

        return $this->invokeMethod($controller, $method, $request, $params);
    }

    /**
     * Resolves controller/action arguments without storing request state in the
     * container. Request and route parameters are request-local values; all
     * other class dependencies come from the long-lived container.
     *
     * Supported conventions:
     *   - Request/ServerRequestInterface parameter => current Request
     *   - array parameter => route parameters
     *   - any other class/interface => container dependency
     *   - untyped first/second parameters => Request/route parameters (legacy)
     *
     * @param array<int|string, string> $params
     */
    private function invokeMethod(object $controller, string $method, Request $request, array $params): mixed
    {
        $reflection = new \ReflectionMethod($controller, $method);
        $arguments = [];

        foreach ($reflection->getParameters() as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType) {
                if (!$type->isBuiltin() && $this->isRequestType($type->getName())) {
                    $arguments[] = $request;
                    continue;
                }

                if ($type->isBuiltin() && $type->getName() === 'array') {
                    $arguments[] = $params;
                    continue;
                }

                if (!$type->isBuiltin()) {
                    if ($this->container === null) {
                        throw new \RuntimeException(sprintf(
                            'Controller dependency [%s] requires a service container.',
                            $type->getName(),
                        ));
                    }
                    $arguments[] = $this->container->get($type->getName());
                    continue;
                }
            }

            // Preserve the original positional convention for untyped actions.
            if ($type === null && $index === 0) {
                $arguments[] = $request;
                continue;
            }
            if ($type === null && $index === 1) {
                $arguments[] = $params;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Cannot resolve controller argument [%s::$%s($%s)]. '
                . 'Type it as Request, array, or a container service.',
                $controller::class,
                $method,
                $parameter->getName(),
            ));
        }

        return $reflection->invokeArgs($controller, $arguments);
    }

    private function isRequestType(string $type): bool
    {
        return $type === Request::class
            || is_a($type, ServerRequestInterface::class, true);
    }

    /** @param callable|array{class-string, string}|class-string $handler */
    private function validateHandler(callable|array|string $handler): void
    {
        if (is_array($handler)) {
            if (count($handler) !== 2) {
                throw new InvalidArgumentException('Controller route handlers must be [class-string, method-string].');
            }

            if (!is_string($handler[0])) {
                if (is_callable($handler)) {
                    return;
                }

                throw new InvalidArgumentException('Route handler arrays must be callable or [class-string, method-string].');
            }

            if (!is_string($handler[1])) {
                throw new InvalidArgumentException('Controller route handlers must be [class-string, method-string].');
            }

            [$class, $method] = $handler;
            if (!class_exists($class)) {
                throw new InvalidArgumentException(sprintf('Controller class [%s] does not exist.', $class));
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->hasMethod($method)) {
                throw new InvalidArgumentException(sprintf('Controller method [%s::%s] does not exist.', $class, $method));
            }

            $methodReflection = $reflection->getMethod($method);
            if (!$methodReflection->isPublic() || $methodReflection->isStatic()) {
                throw new InvalidArgumentException(sprintf(
                    'Controller method [%s::%s] must be public and non-static.',
                    $class,
                    $method,
                ));
            }

            return;
        }

        if (is_callable($handler)) {
            return;
        }

        if (!class_exists($handler)) {
            throw new InvalidArgumentException(sprintf('Action class [%s] does not exist.', $handler));
        }

        $reflection = new \ReflectionClass($handler);
        if (!$reflection->isInstantiable() || !$reflection->hasMethod('__invoke')) {
            throw new InvalidArgumentException(sprintf(
                'Action class [%s] must be instantiable and implement __invoke().',
                $handler,
            ));
        }

        if (!$reflection->getMethod('__invoke')->isPublic()) {
            throw new InvalidArgumentException(sprintf('Action class [%s::__invoke] must be public.', $handler));
        }
    }

    /** @return array<string, mixed>|null */
    private function routeByOrder(int $order): ?array
    {
        return $this->routesByOrder[$order] ?? null;
    }

    /**
     * @param list<mixed> $middleware
     * @return list<PsrMiddlewareInterface>
     */
    private function resolveMiddleware(array $middleware): array
    {
        if ($middleware === []) {
            return [];
        }

        if ($this->middlewareRegistry === null || $this->container === null) {
            foreach ($middleware as $item) {
                if (!$item instanceof PsrMiddlewareInterface) {
                    throw new InvalidArgumentException(
                        'Route middleware requires a container-backed MiddlewareRegistry or PSR-15 instances.',
                    );
                }
            }

            return array_values($middleware);
        }

        return $this->middlewareRegistry->resolve($middleware, $this->container);
    }

    private function rebuildDispatcher(): void
    {
        $this->allowedMethodsByShape = [];
        $this->dispatcher = \FastRoute\simpleDispatcher(
            function (RouteCollector $collector): void {
                $pathShapes = [];
                $allowedMethodsByShape = [];
                foreach ($this->routes as $route) {
                    $collector->addRoute($route['method'], $route['pattern'], $route['order']);
                    $allowedMethodsByShape[$route['shape']][] = $route['method'];
                    if ($route['method'] === 'GET') {
                        $allowedMethodsByShape[$route['shape']][] = 'HEAD';
                    }
                    if (isset($pathShapes[$route['shape']])) {
                        continue;
                    }

                    $pathShapes[$route['shape']] = true;
                    $collector->addRoute(self::PATH_METHOD, $route['pattern'], $route['order']);
                }

                foreach ($allowedMethodsByShape as $shape => $methods) {
                    $methods[] = 'OPTIONS';
                    $this->allowedMethodsByShape[$shape] = $this->sortMethods(array_values(array_unique($methods)));
                }
            },
            [
                'cacheDisabled' => true,
            ],
        );
    }

    /**
     * @return array{
     *     pattern: string,
     *     shape: string,
     *     specificity: list<int>,
     *     literalLength: int,
     * }
     */
    private function compilePattern(string $pattern): array
    {
        $normalized = $this->normalizePath($pattern);
        if (str_contains($normalized, '[') || str_contains($normalized, ']')) {
            throw new InvalidArgumentException(sprintf(
                'Route pattern [%s] uses reserved optional-segment syntax.',
                $pattern,
            ));
        }
        $segments = $normalized === '/' ? [] : explode('/', trim($normalized, '/'));
        $parameterNames = [];
        $regexSegments = [];
        $shapeSegments = [];
        $specificity = [];
        $literalLength = 0;

        foreach ($segments as $segment) {
            $segmentRegex = '';
            $shapeSegment = '';
            $cursor = 0;
            $hasParameter = false;
            $hasLiteral = false;

            preg_match_all('/\{[^{}]*\}/', $segment, $tokens, PREG_OFFSET_CAPTURE);
            foreach ($tokens[0] as [$token, $offset]) {
                $literal = substr($segment, $cursor, $offset - $cursor);
                if (str_contains($literal, '{') || str_contains($literal, '}')) {
                    throw new InvalidArgumentException(sprintf(
                        'Route pattern [%s] contains invalid parameter syntax.',
                        $pattern,
                    ));
                }

                if ($literal !== '') {
                    $segmentRegex .= preg_quote($literal, '#');
                    $shapeSegment .= $literal;
                    $literalLength += strlen($literal);
                    $hasLiteral = true;
                }

                if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/D', $token, $parameter) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Route pattern [%s] contains an invalid parameter [%s].',
                        $pattern,
                        $token,
                    ));
                }

                $name = $parameter[1];
                if (in_array($name, $parameterNames, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Route pattern [%s] contains duplicate parameter names.',
                        $pattern,
                    ));
                }

                if ($hasParameter && $literal === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Route pattern [%s] contains adjacent parameters.',
                        $pattern,
                    ));
                }

                $parameterNames[] = $name;
                $segmentRegex .= '(?P<' . $name . '>[^/]+)';
                $shapeSegment .= '{}';
                $hasParameter = true;
                $cursor = $offset + strlen($token);
            }

            $trailing = substr($segment, $cursor);
            if (str_contains($trailing, '{') || str_contains($trailing, '}')) {
                throw new InvalidArgumentException(sprintf(
                    'Route pattern [%s] contains invalid parameter syntax.',
                    $pattern,
                ));
            }

            if ($trailing !== '') {
                $segmentRegex .= preg_quote($trailing, '#');
                $shapeSegment .= $trailing;
                $literalLength += strlen($trailing);
                $hasLiteral = true;
            }

            if (!$hasParameter && !$hasLiteral && $segment !== '') {
                $segmentRegex = preg_quote($segment, '#');
                $shapeSegment = $segment;
            }

            $regexSegments[] = $segmentRegex;
            $shapeSegments[] = $shapeSegment;
            $specificity[] = $hasParameter
                ? ($hasLiteral ? 1 : 0)
                : 2;
        }

        $regex = '#^/' . implode('/', $regexSegments) . '$#';
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException('Invalid route pattern: ' . $pattern);
        }

        return [
            'pattern' => $normalized,
            'shape' => '/' . implode('/', $shapeSegments),
            'specificity' => $specificity,
            'literalLength' => $literalLength,
        ];
    }

    private function normalizeMethod(string $method): string
    {
        $normalized = strtoupper(trim($method));
        if ($normalized === '' || preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP method [%s].', $method));
        }
        if ($normalized === self::PATH_METHOD) {
            throw new InvalidArgumentException(sprintf('HTTP method [%s] is reserved.', $method));
        }

        return $normalized;
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new InvalidArgumentException('URI path contains an invalid percent-encoding.');
        }

        // Decode percent-encoding for matching so encoded non-ASCII static
        // segments match their literal route, but keep encoded slashes and
        // backslashes intact (%2F, %5C) so they stay inside a single segment
        // instead of splitting the route. Captured values are decoded again in
        // invoke() so handlers still receive the real parameter.
        $path = str_ireplace(['%2f', '%5c'], ['%252f', '%255c'], $path);
        $path = rawurldecode($path);

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('URI path contains control characters.');
        }

        if ($path === '') {
            return '/';
        }

        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    /**
     * @param array{specificity: list<int>, literalLength: int, order: int} $left
     * @param array{specificity: list<int>, literalLength: int, order: int} $right
     */
    private function compareRoutes(array $left, array $right): int
    {
        foreach ($left['specificity'] as $index => $leftRank) {
            $rightRank = $right['specificity'][$index] ?? 0;
            if ($leftRank !== $rightRank) {
                return $rightRank <=> $leftRank;
            }
        }

        if ($left['literalLength'] !== $right['literalLength']) {
            return $right['literalLength'] <=> $left['literalLength'];
        }

        return $left['order'] <=> $right['order'];
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private function sortMethods(array $methods): array
    {
        $order = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        usort($methods, static function (string $left, string $right) use ($order): int {
            $leftIndex = array_search($left, $order, true);
            $rightIndex = array_search($right, $order, true);

            return ($leftIndex === false ? PHP_INT_MAX : $leftIndex)
                <=> ($rightIndex === false ? PHP_INT_MAX : $rightIndex);
        });

        return $methods;
    }
}
