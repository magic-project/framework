# Magic Framework

一个面向个人项目的轻量 PHP 应用内核，当前优先支持 Swoole 长驻进程运行方式。

## PHP 兼容性

- 支持 PHP 8.4–8.5；
- 不支持 PHP 8.2 和 8.3；
- PHP 8.6 在稳定版及运行时扩展完成验证后再加入；
- Swoole 作为当前 Runtime；Swow 作为后续 Runtime 扩展。

## 当前状态

当前 P0/P1 基础能力和 P2 Todo 真实业务样例已完成：

- Application
- Container
- Request Context
- Request/Response
- Router
- FastRoute-backed route matching with Magic-compatible error/HEAD/OPTIONS semantics
- Middleware
- Request ID
- Todo 示例中的 `/health` 路由
- 状态隔离测试
- Swoole Runtime
- Swoole 请求/响应适配
- 请求生命周期钩子
- 请求结束自动清理（`defer`）
- 配置、PSR-3 结构化日志、统一错误响应
- HTTP 消息实现遵循 PSR-7，容器实现 PSR-11，内置 PSR-15 Middleware/Handler，提供 PSR-17 `HttpFactory`
- Todo 示例中的 `/health`、`/ready` 应用层路由
- 请求大小限制、应用/Worker 生命周期、优雅停止态
- Swoole 优雅停止等待时间由 `runtime.shutdown_timeout` 控制，并映射到 `max_wait_time`
- Swoole worker 默认按 `runtime.max_request` 请求数回收，并以 `runtime.max_coroutine` 限制协程数量
- Swoole HTTP 入口在读取 body 前执行 `max_body_bytes`、`max_header_bytes`、`max_uri_bytes`、`max_query_bytes` 和 `max_query_params` 限制
- 通用 CLI 命令注册、参数解析、输出和退出码
- SQLite Todo API 示例（见 `examples/todo`）

## 开始运行

```bash
composer install
composer test
```

HTTP 层的 `Magic\Http\Request` / `Response` 分别实现 PSR-7 的
`ServerRequestInterface` / `ResponseInterface`；`Magic\Container\Container` 实现
PSR-11。需要与 PSR-15/17 生态集成时，可直接使用内置的 middleware/handler 和
`Magic\Http\HttpFactory`。`Magic\Http\Middleware\MiddlewareInterface` 现在是严格的
PSR-15 合约。应用中间件注册只接受 `Psr\Http\Server\MiddlewareInterface`；
旧版以 `Magic\Http\Request` 为参数的中间件需要迁移到 PSR-15 签名。

使用 Swoole 启动：

```bash
php bin/magic start
```

Swoole 默认监听地址和端口从 `server.host` / `server.port` 读取，也可通过
`SERVER_HOST` / `SERVER_PORT` 环境变量覆盖。

提交前检查：

```bash
composer check
```

真实 Swoole TCP/多进程集成测试需要在允许本地监听端口的环境中运行：

```bash
composer test:integration
```

Swoole Runtime 使用协程级 Request Context，因此 `enable_coroutine` 必须保持为
`true`。如果应用在启动前显式传入或配置了 Context Storage，Runtime 会保留该配置；
否则自动切换为 `SwooleContextStorage`。应用可通过 `$app->metrics()->snapshot()` 读取
当前进程的请求耗时、错误数、活跃请求数和 worker 计数，并自行导出到监控系统。

运行 CLI：

```bash
php bin/magic list
php bin/magic help
php bin/magic version
php bin/magic start
php bin/magic --version
```

`start` 默认读取 `server.host`、`server.port`；也可以覆盖：

```bash
php bin/magic start --host=127.0.0.1 --port=9502
# 等价写法：php bin/magic start --host 127.0.0.1 --port 9502
```

长驻进程建议按环境调整 `config/config.php` 中的边界：

```php
'http' => [
    'max_body_bytes' => 1_048_576,
    'max_header_bytes' => 65_536,
    'max_uri_bytes' => 8_192,
    'max_query_bytes' => 4_096,
    'max_query_params' => 100,
],
'runtime' => [
    'max_request' => 10_000,
    'max_coroutine' => 10_000,
],
```

无命令定义时，`--name=value` 表示带值选项，`--flag` 表示布尔选项；位置参数会按原顺序传给命令。

业务 Provider 可以在 `register()` 阶段注册命令：

```php
use Magic\Console\CommandRegistry;

public function register(Container $container): void
{
    $container->get(CommandRegistry::class)->register(App\Console\AboutCommand::class);
}
```

命令实现 `Magic\Console\CommandInterface`，接收 `CommandInput` 和
`CommandOutput`，并返回 `0–255` 范围内的进程退出码。命令执行前后会复用应用的
Provider、配置、容器和 boot/shutdown 生命周期；CLI 不会伪造 HTTP RequestContext。

配置服务 Provider：

```php
return [
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
    'bindings' => [
        App\Contract\UserRepository::class => App\Repository\SqlUserRepository::class,
    ],
];
```

上述内容放在 `config/autoload.php` 中，并在应用 bootstrap 时显式传给 `Application`：

```php
$app = new Application(
    config: $config,
    autoloadPath: __DIR__ . '/../config/autoload.php',
);
```

Middleware 通过 `config/middleware.php` 配置。应用显式传入
`autoloadPath` 时，框架会从同一目录自动加载旁边的 `middleware.php`；也可以通过
`middlewarePath` 显式指定路径。配置文件可以返回全局 Middleware 列表：

```php
return [
    App\Http\Middleware\AuthMiddleware::class,
];
```

也可以在同一个文件中定义可复用的 Middleware Group：

```php
return [
    'global' => [
        App\Http\Middleware\RequestLogMiddleware::class,
    ],
    'groups' => [
        'admin' => [
            App\Http\Middleware\RequireLoginMiddleware::class,
        ],
    ],
];
```

Middleware 类会通过容器解析。旧版配置仍可以返回接收 `Application` 的工厂，但新代码
建议只使用数组声明。具体路由在 `config/routers.php` 中声明 Middleware：

```php
Route::get('/admin/users', $handler, middleware: ['admin']);
```

未在路由定义中声明的路由不会自动执行路由 Middleware。应用 boot 后 Middleware 和路由
Middleware 均被冻结。

框架不会根据当前工作目录猜测项目配置文件，避免从不同目录启动时加载到意外的 Provider 或 binding。未传 `autoloadPath` 时只使用框架自己的默认配置文件；应用项目应始终显式传入自己的路径。Provider 的 `register()` 阶段负责注册服务，应用启动时再统一执行 `boot()`。具体对象在首次获取时自动装配并按应用单例缓存。

默认服务是 worker 级单例，也可以显式使用 `$container->singleton()`；请求态对象使用
`$container->requestScoped()`，容器会把实例放入当前 `RequestContext`，请求结束时随上下文一起释放。
每次都需要新实例的对象使用 `$container->prototype()`。
应用 boot 后路由、middleware、生命周期 hook 和容器结构会冻结，避免业务请求重复追加闭包、路由或服务定义。
同一个 Application 实例只支持一次 boot/run 生命周期；shutdown 后不能再次启动或处理请求。
CLI 命令注册表也会在应用 boot 后冻结。

每个请求在进入 Application 时只生成一次 Request ID，并写入 RequestContext；正常响应、middleware 短路、异常、请求限制和停止态响应都会返回同一个 `X-Request-Id`。应用进入 stopping 后，已经准入的请求继续完成，之后进入的请求统一返回 503。

### 健康检查边界

健康检查和 readiness 属于应用与部署层的业务策略，不属于 Magic Framework 核心能力。
框架不提供内置的 `HealthCheckRegistry`、`Application::healthChecks()` 或
`Application::onReadiness()` API，也不会自动注册 `/health` 或 `/ready` 路由。

应用应在自己的路由中实现这些接口，并自行决定要检查哪些依赖、返回哪些字段以及是否隐藏错误详情。
Todo 示例中的 `/health` 和 `/ready` 仅用于展示这种应用层实现方式。后续新增功能时，不应因为出现健康检查需求就把相关状态、检查器或路由抽入框架核心。

Provider 可以继承 `Magic\Container\ServiceProvider`：

```php
final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->register(
            UserRepository::class,
            SqlUserRepository::class,
        );
    }
}
```

注册请求结束清理：

```php
$app->onRequestStart(static function (RequestContext $context): void {
    $context->defer(static function (): void {
        // 请求结束时释放资源
    });
});
```

注册 HTTP 路由：

示例路由统一放在 `config/routers.php`，由 `bootstrap/app.php` 在局部 Router 作用域内加载，写法接近 Laravel：

```php
use Magic\Routing\Route;

return static function (): void {
    Route::get('/users', $listUsers);
    Route::post('/users', $createUser);
    Route::put('/users/{id}', $replaceUser);
    Route::patch('/users/{id}', $updateUser);
    Route::delete('/users/{id}', $deleteUser);
    Route::match(['GET', 'POST'], '/batch', $batchHandler);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::get('/health', HealthAction::class); // invokable action
};
```

Route handlers may be closures, callable objects, `[Controller::class, 'method']`, or an invokable action class. Controller methods must be public, non-static methods. Method arguments are resolved per request: `Request`/`ServerRequestInterface` receives the current request, `array` receives route parameters, and other class types are resolved from the container. Untyped first and second arguments retain the legacy request/route-parameter convention. Controllers that are not explicitly registered are created fresh for each request through `resolveController()`, while explicit container bindings keep their configured lifecycle. A controller constructor cannot inject the concrete request; use an action parameter or `CurrentRequest` instead. Invalid controller targets fail during route registration.

`Route` Facade 只在路由文件加载期间绑定到当前 `Application` 的 Router，加载完成后会自动恢复，避免全局路由状态泄漏。Router 会区分 404（路径不存在）和 405（路径存在但方法不允许），405 和自动响应的
`OPTIONS` 都会返回 `Allow` header。没有单独注册 `HEAD` 时，`HEAD` 会复用对应的
`GET` handler，但响应体会被抑制。静态路径优先于参数路径，路径尾部的 `/` 会被统一规范化，
路由参数会按 URI 规则解码。相同路径形状和 HTTP 方法不能重复注册，非法参数语法会在启动时失败。

旧版 `Route::setRouter()` 仍保留兼容性；新代码应直接使用 `Application` 注册路由，或使用
`Route::withRouter()` 做局部注册，避免全局 Router 状态泄漏。

控制器中读取请求数据时，推荐使用业务快捷 API：

```php
$page = $request->get('page', 1);             // URL Query
$name = $request->input('name');              // Query + Body
$avatar = $request->file('avatar');           // 上传文件
$userId = $request->route('id');              // 路由参数
```

`query()`、`post()`、`input()` 和 `file()` 不使用静态缓存，解析结果只保存在当前
`Request` 实例内，适合常驻内存运行时。底层 PSR-7 API
`getQueryParams()`、`getParsedBody()`、`getUploadedFiles()` 和 `getAttributes()` 继续保留。

Controller 方法可以直接接收当前请求：

```php
final class Index
{
    public function test(Request $request): mixed
    {
        return $request->input('page', 'test');
    }
}
```

Controller 构造函数建议只注入稳定服务。需要在深层服务中访问当前请求时，注入
`CurrentRequest` 代理；不要把具体 `Request` 保存到长期 singleton 中。

然后访问：

```text
http://127.0.0.1:8000/health
```

## 设计原则

- 先服务真实项目，再抽象通用能力；
- 业务代码不直接依赖某个运行时；
- 不重复实现 ORM、模板引擎等成熟组件；
- 长驻进程安全和请求隔离优先于功能数量。

