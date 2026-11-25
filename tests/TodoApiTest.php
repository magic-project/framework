<?php

declare(strict_types=1);

namespace Magic\Tests;

use Magic\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/examples/todo/bootstrap.php';

final class TodoApiTest extends TestCase
{
    public function testTodoApiSupportsCreateReadAndDelete(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $app = createTodoApp($pdo);

        $created = $app->handle(new Request(
            'POST',
            '/todos',
            ['content-type' => 'application/json'],
            [],
            '{"title":"write docs"}',
        ));
        self::assertSame(201, $created->status());
        $todo = json_decode($created->body(), true);
        self::assertSame('write docs', $todo['title']);

        $listed = $app->handle(new Request('GET', '/todos'));
        self::assertSame([$todo], json_decode($listed->body(), true));

        $deleted = $app->handle(new Request('DELETE', '/todos/' . $todo['id']));
        self::assertSame(204, $deleted->status());
        self::assertSame('', $deleted->body());
    }

    public function testTodoApiValidatesJsonAndReportsReadiness(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $app = createTodoApp($pdo);

        $invalid = $app->handle(new Request(
            'POST',
            '/todos',
            ['content-type' => 'application/json'],
            [],
            '{"completed":true}',
        ));
        self::assertSame(422, $invalid->status());

        $ready = $app->handle(new Request('GET', '/ready'));
        self::assertSame(200, $ready->status());
        self::assertSame('ok', json_decode($ready->body(), true)['status']);
    }

    public function testTodoApiUpdatesAndReturnsMissingTodosAsNotFound(): void
    {
        $app = createTodoApp(new PDO('sqlite::memory:'));
        $created = $app->handle(new Request(
            'POST',
            '/todos',
            ['content-type' => 'application/json'],
            [],
            '{"title":"draft","completed":false}',
        ));
        $todo = json_decode($created->body(), true);

        $updated = $app->handle(new Request(
            'PATCH',
            '/todos/' . $todo['id'],
            ['content-type' => 'application/json'],
            [],
            '{"title":"published","completed":true}',
        ));
        self::assertSame(200, $updated->status());
        self::assertSame(['title' => 'published', 'completed' => true], array_intersect_key(
            json_decode($updated->body(), true),
            ['title' => true, 'completed' => true],
        ));

        $missing = $app->handle(new Request('GET', '/todos/999'));
        self::assertSame(404, $missing->status());
    }
}
