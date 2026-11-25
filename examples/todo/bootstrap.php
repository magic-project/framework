<?php

declare(strict_types=1);

use Example\Todo\TodoRepository;
use Magic\Application;
use Magic\Config\Config;
use Magic\Http\ErrorResponse;
use Magic\Http\Request;
use Magic\Http\Response;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function createTodoApp(?PDO $pdo = null): Application
{
    $config = Config::fromEnvironment([
        ...Config::environment(),
        'APP_SERVICE' => 'magic-todo',
    ]);
    $app = new Application(
        config: $config,
        autoloadPath: dirname(__DIR__, 2) . '/config/autoload.php',
    );

    $dbPath = getenv('TODO_DB_PATH') ?: sys_get_temp_dir() . '/magic-todo.sqlite';
    $repository = null;
    $repositoryProvider = static function () use (&$pdo, &$repository, $dbPath): TodoRepository {
        if (!$repository instanceof TodoRepository) {
            $pdo ??= new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $repository = new TodoRepository($pdo);
        }

        return $repository;
    };
    if ($pdo !== null) {
        $repositoryProvider();
    }
    $app->onWorkerStart(static function () use ($repositoryProvider): void {
        $repositoryProvider();
    });
    $app->onWorkerStop(static function () use (&$pdo, &$repository): void {
        $repository = null;
        $pdo = null;
    });

    $decodeTodoPayload = static function (Request $request, bool $requireCompleted): array|Response {
        if (!$request->isJson()) {
            return ErrorResponse::json('unsupported_media_type', 'Content-Type must be application/json.', 415);
        }

        try {
            $payload = $request->json();
        } catch (\JsonException) {
            return ErrorResponse::json('invalid_json', 'Request body must contain valid JSON.', 400);
        }

        if (!is_array($payload) || !is_string($payload['title'] ?? null)) {
            return ErrorResponse::json('validation_failed', 'The title field is required.', 422);
        }

        $title = trim($payload['title']);
        if ($title === '' || strlen($title) > 200) {
            return ErrorResponse::json('validation_failed', 'The title must contain 1 to 200 characters.', 422);
        }

        if ($requireCompleted && !is_bool($payload['completed'] ?? null)) {
            return ErrorResponse::json('validation_failed', 'The title and completed fields are required.', 422);
        }
        if (!$requireCompleted && array_key_exists('completed', $payload) && !is_bool($payload['completed'])) {
            return ErrorResponse::json('validation_failed', 'The completed field must be boolean.', 422);
        }

        return [
            'title' => $title,
            'completed' => (bool) ($payload['completed'] ?? false),
        ];
    };

    $app->get('/health', static function () use ($app): Response {
        return Response::json([
            'status' => 'ok',
            'service' => $app->config()->get('app.service', 'magic-framework'),
        ]);
    });
    $app->get('/ready', static function () use ($app, $repositoryProvider): Response {
        $includeErrorDetails = (bool) $app->config()->get('app.debug', false);
        $check = ['ok' => false];

        try {
            $ok = $repositoryProvider()->ping();
            $check = ['ok' => $ok];
            if (!$ok) {
                $check['error'] = 'Health check failed.';
            }
        } catch (\Throwable $exception) {
            $check = [
                'ok' => false,
                'error' => $includeErrorDetails ? $exception->getMessage() : 'Health check failed.',
            ];
        }

        $report = [
            'status' => $check['ok'] ? 'ok' : 'failed',
            'checks' => ['database' => $check],
        ];

        return Response::json($report, $report['status'] === 'ok' ? 200 : 503);
    });

    $app->get('/todos', static function (Request $request) use ($repositoryProvider): array {
        $limit = (int) ($request->query()['limit'] ?? 50);
        $offset = (int) ($request->query()['offset'] ?? 0);

        return $repositoryProvider()->all($limit, $offset);
    });
    $app->get('/todos/{id}', static function (Request $request, array $params) use ($repositoryProvider): Response {
        $todo = $repositoryProvider()->find((int) $params['id']);

        return $todo === null
            ? ErrorResponse::json('not_found', 'Todo Not Found', 404)
            : Response::json($todo);
    });
    $app->post('/todos', static function (Request $request) use ($repositoryProvider, $decodeTodoPayload): Response {
        $payload = $decodeTodoPayload($request, false);
        if ($payload instanceof Response) {
            return $payload;
        }

        return Response::json($repositoryProvider()->create($payload['title'], $payload['completed']), 201);
    });
    $updateTodo = static function (Request $request, array $params) use ($repositoryProvider, $decodeTodoPayload): Response {
        $payload = $decodeTodoPayload($request, true);
        if ($payload instanceof Response) {
            return $payload;
        }

        $todo = $repositoryProvider()->update((int) $params['id'], $payload['title'], $payload['completed']);

        return $todo === null
            ? ErrorResponse::json('not_found', 'Todo Not Found', 404)
            : Response::json($todo);
    };
    $app->put('/todos/{id}', $updateTodo);
    $app->patch('/todos/{id}', $updateTodo);
    $app->delete('/todos/{id}', static function (Request $request, array $params) use ($repositoryProvider): Response {
        return $repositoryProvider()->delete((int) $params['id'])
            ? new Response('', 204)
            : ErrorResponse::json('not_found', 'Todo Not Found', 404);
    });

    return $app;
}
