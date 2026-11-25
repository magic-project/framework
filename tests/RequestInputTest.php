<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Application;
use Magic\Http\Request;
use Magic\Http\CurrentRequest;
use Magic\Http\Stream;
use Magic\Http\UploadedFile;
use Magic\Runtime\Swoole\SwooleRequestFactory;
use PHPUnit\Framework\TestCase;

final class RequestInputTest extends TestCase
{
    public function testRequestProvidesBusinessInputShortcuts(): void
    {
        $request = new Request(
            'POST',
            '/users/42?page=2',
            ['content-type' => 'application/json'],
            ['page' => '2', 'q' => 'search'],
            '{"name":"alice","profile":{"role":"admin"}}',
            '/users/42?page=2',
            'page=2',
        );

        self::assertSame('2', $request->get('page'));
        self::assertSame('alice', $request->post('name'));
        self::assertSame('admin', $request->input('profile.role'));
        self::assertSame('search', $request->input('q'));
        self::assertSame('alice', $request->input('name'));
        self::assertSame('{"name":"alice","profile":{"role":"admin"}}', $request->rawBody());
    }

    public function testRequestProvidesRouteAndFileShortcuts(): void
    {
        $file = new UploadedFile(Stream::fromString('contents'), 8, UPLOAD_ERR_OK, 'avatar.txt', 'text/plain');
        $request = (new Request('POST', '/users/42', uploadedFiles: ['avatar' => $file]))
            ->withAttribute('route_params', ['id' => '42']);

        self::assertSame('42', $request->route('id'));
        self::assertSame($file, $request->file('avatar'));
        self::assertTrue($request->hasFile('avatar'));
        self::assertSame(['avatar' => $file], $request->files());
    }

    public function testSwooleFactoryMapsPostJsonAndUploadedFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'magic-upload-');
        self::assertNotFalse($path);
        file_put_contents($path, 'upload');

        try {
            $incoming = new class($path) {
                public array $server = ['request_method' => 'POST', 'request_uri' => '/upload'];
                public array $header = ['content-type' => 'multipart/form-data'];
                public array $get = ['page' => '3'];
                public array $post = ['title' => 'demo'];
                public array $files;

                public function __construct(string $path)
                {
                    $this->files = [
                        'attachment' => [
                            'name' => 'demo.txt',
                            'type' => 'text/plain',
                            'tmp_name' => $path,
                            'error' => UPLOAD_ERR_OK,
                            'size' => 6,
                        ],
                    ];
                }

                public function rawContent(): string
                {
                    return '';
                }
            };

            $request = SwooleRequestFactory::from($incoming);

            self::assertSame('3', $request->get('page'));
            self::assertSame('demo', $request->post('title'));
            self::assertTrue($request->hasFile('attachment'));
            self::assertSame('demo.txt', $request->file('attachment')->getClientFilename());
        } finally {
            @unlink($path);
        }
    }

    public function testCurrentRequestReadsTheCoroutineRequestContext(): void
    {
        $app = new Application();
        $current = $app->container()->get(CurrentRequest::class);
        $app->get('/current', static function () use ($current): array {
            return ['path' => $current->get()->path()];
        });

        $response = $app->handle(new Request('GET', '/current'));

        self::assertSame(['path' => '/current'], json_decode($response->body(), true));
    }
}
