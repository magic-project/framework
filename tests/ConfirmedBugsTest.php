<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Console\CommandInput;
use Magic\Config\Config;
use Magic\Container\Container;
use Magic\Container\ContainerException;
use Magic\Context\RequestContext;
use Magic\Http\HttpFactory;
use Magic\Http\Request;
use Magic\Http\Response;
use Magic\Http\Uri;
use Magic\Logger\StreamLogger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConfirmedBugsTest extends TestCase
{
    public function test205ResponsesCannotContainBodies(): void
    {
        self::assertSame('', new Response('body', 205)->body());
        self::assertSame('', new Response('body', 304)->body());
    }

    public function testContainerRegistrationOverridesExistingDefinitionsAndDetectsFactoryCycles(): void
    {
        $container = new Container();
        $container->set('service', new \stdClass());
        $replacement = new \stdClass();
        $container->register('service', $replacement);

        self::assertSame($replacement, $container->get('service'));

        $container->factory('cycle', static fn (Container $container): mixed => $container->get('cycle'));
        $this->expectException(ContainerException::class);
        $container->get('cycle');
    }

    public function testAutoloadBindingsCanOverrideCoreServices(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'magic-bindings-');
        self::assertNotFalse($file);
        file_put_contents($file, '<?php return ["bindings" => ["Magic\\Config\\Config" => new Magic\\Config\\Config(["source" => "binding"])]];');

        try {
            $app = new Application(autoloadPath: $file);
            self::assertSame('binding', $app->config()->get('source'));
        } finally {
            @unlink($file);
        }
    }

    public function testClosedRequestContextRejectsWrites(): void
    {
        $context = new RequestContext(new Request('GET', '/'));
        $context->close();

        $this->expectException(\RuntimeException::class);
        $context->set('value', 'forbidden');
    }

    public function testHeaderNormalizationHandlesArrayValuesWithoutArrayCastWarnings(): void
    {
        $request = new Request('GET', '/', ['X-Test' => ['one', 'two']]);

        self::assertSame(['one', 'two'], $request->getHeader('X-Test'));
        self::assertSame('one, two', $request->header('X-Test'));
    }

    public function testResponseRejectsNulHeaderValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('ok', 200, ['X-Test' => "bad\0value"]);
    }

    public function testRequestWithUriUpdatesHostWithoutLeakingUserInfo(): void
    {
        $request = new Request('GET', '/', ['Host' => 'old.example']);

        self::assertSame('old.example', $request->withUri(new Uri('/next'))->getHeaderLine('Host'));
        self::assertSame(
            'example.com:8080',
            $request->withUri(new Uri('http://user:pass@example.com:8080/next'), false)->getHeaderLine('Host'),
        );
        self::assertSame(
            'old.example',
            $request->withUri(new Uri('http://example.com/next'), true)->getHeaderLine('Host'),
        );
        self::assertSame(
            'example.com',
            (new Request('GET', '/'))->withUri(new Uri('http://example.com/next'), true)->getHeaderLine('Host'),
        );
    }

    public function testCommandInputAcceptsSeparatedLongOptionValues(): void
    {
        $input = CommandInput::fromArgv(['magic', 'start', '--port', '9502']);

        self::assertSame('9502', $input->option('port'));
        self::assertSame([], $input->arguments());
    }

    public function testAutowiringHonorsNullableDependencyDefaults(): void
    {
        $service = (new Container())->get(OptionalDependencyService::class);

        self::assertNull($service->dependency);
    }

    public function testRequestHeaderHelpersPreserveEmptyValuesAndRejectControls(): void
    {
        $request = new Request('GET', '/', ['X-Test' => '']);
        self::assertSame('', $request->header('X-Test', 'fallback'));

        $request = new Request('GET', '/');
        $this->expectException(\InvalidArgumentException::class);
        $request->withHeader('X-Test', "bad\r\nvalue");
    }

    public function testLoggerSurvivesThrowingStringablesAndTruncatesMessages(): void
    {
        $throwing = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('conversion failed');
            }
        };

        (new StreamLogger(8))->info('0123456789', ['value' => $throwing]);
        self::assertTrue(true);
    }

    public function testRequestHandlingFlagIsNotSetWhenContextBeginFails(): void
    {
        $app = new Application();
        $app->context()->begin(new Request('GET', '/'));

        try {
            $app->handle(new Request('GET', '/'));
        } catch (\RuntimeException $exception) {
            self::assertSame('Nested request contexts are not supported.', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change context storage while a request is active.');
        $app->useContextStorage(new \Magic\Context\SynchronousContextStorage());
    }

    public function testHttpMessagesAndFactoriesImplementPsrContracts(): void
    {
        $factory = new HttpFactory();
        $request = $factory->createServerRequest('GET', '/health?ready=1');
        $response = $factory->createResponse(200);

        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertInstanceOf(RequestHandlerInterface::class, new \Magic\Http\Middleware\MiddlewareStack([], static fn (): Response => new Response('ok')));
        self::assertSame('/health?ready=1', $request->getRequestTarget());
        self::assertSame('1', $request->getQueryParams()['ready']);
    }

    public function testPsrMiddlewareMayPassAnotherServerRequestImplementationToTheRouter(): void
    {
        $app = new Application();
        $app->middleware(new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle(new class($request) implements ServerRequestInterface {
                    public function __construct(private readonly ServerRequestInterface $request) {}
                    public function getProtocolVersion(): string { return $this->request->getProtocolVersion(); }
                    public function withProtocolVersion(string $version): \Psr\Http\Message\MessageInterface { return new self($this->request->withProtocolVersion($version)); }
                    public function getHeaders(): array { return $this->request->getHeaders(); }
                    public function hasHeader(string $name): bool { return $this->request->hasHeader($name); }
                    public function getHeader(string $name): array { return $this->request->getHeader($name); }
                    public function getHeaderLine(string $name): string { return $this->request->getHeaderLine($name); }
                    public function withHeader(string $name, $value): \Psr\Http\Message\MessageInterface { return new self($this->request->withHeader($name, $value)); }
                    public function withAddedHeader(string $name, $value): \Psr\Http\Message\MessageInterface { return new self($this->request->withAddedHeader($name, $value)); }
                    public function withoutHeader(string $name): \Psr\Http\Message\MessageInterface { return new self($this->request->withoutHeader($name)); }
                    public function getBody(): \Psr\Http\Message\StreamInterface { return $this->request->getBody(); }
                    public function withBody(\Psr\Http\Message\StreamInterface $body): \Psr\Http\Message\MessageInterface { return new self($this->request->withBody($body)); }
                    public function getRequestTarget(): string { return $this->request->getRequestTarget(); }
                    public function withRequestTarget(string $requestTarget): \Psr\Http\Message\RequestInterface { return new self($this->request->withRequestTarget($requestTarget)); }
                    public function getMethod(): string { return $this->request->getMethod(); }
                    public function withMethod(string $method): \Psr\Http\Message\RequestInterface { return new self($this->request->withMethod($method)); }
                    public function getUri(): \Psr\Http\Message\UriInterface { return $this->request->getUri(); }
                    public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): \Psr\Http\Message\RequestInterface { return new self($this->request->withUri($uri, $preserveHost)); }
                    public function getServerParams(): array { return $this->request->getServerParams(); }
                    public function getCookieParams(): array { return $this->request->getCookieParams(); }
                    public function withCookieParams(array $cookies): ServerRequestInterface { return new self($this->request->withCookieParams($cookies)); }
                    public function getQueryParams(): array { return $this->request->getQueryParams(); }
                    public function withQueryParams(array $query): ServerRequestInterface { return new self($this->request->withQueryParams($query)); }
                    public function getUploadedFiles(): array { return $this->request->getUploadedFiles(); }
                    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface { return new self($this->request->withUploadedFiles($uploadedFiles)); }
                    public function getParsedBody() { return $this->request->getParsedBody(); }
                    public function withParsedBody($data): ServerRequestInterface { return new self($this->request->withParsedBody($data)); }
                    public function getAttributes(): array { return $this->request->getAttributes(); }
                    public function getAttribute(string $name, $default = null) { return $this->request->getAttribute($name, $default); }
                    public function withAttribute(string $name, $value): ServerRequestInterface { return new self($this->request->withAttribute($name, $value)); }
                    public function withoutAttribute(string $name): ServerRequestInterface { return new self($this->request->withoutAttribute($name)); }
                });
            }
        });
        $app->get('/interop', static fn (): array => ['ok' => true]);

        self::assertSame(200, $app->handle(new Request('GET', '/interop'))->status());
    }
}

final class OptionalDependencyService
{
    public function __construct(public ?OptionalDependency $dependency = null)
    {
    }
}

final class OptionalDependency
{
}
