# Magic Framework 设计方案

## 定位

Magic Framework 是一个面向个人和小型团队的轻量 PHP 应用内核，优先解决长驻进程应用的生命周期、请求隔离和运行时适配问题。

它不是为了替代 Laravel、Symfony 或 Hyperf，也不以功能数量和 benchmark 作为第一目标。

## 第一阶段目标

- PHP 8.4–8.5（当前生产支持矩阵）
- PSR-4 自动加载
- 基础 HTTP Request/Response
- Router
- Middleware
- 最小依赖容器
- Request Context
- Swoole Runtime
- 请求生命周期钩子
- 请求结束清理回调
- 请求状态隔离测试

## 第二阶段目标

- Swow Runtime
- PHP 8.6（稳定版发布并完成 Swoole/Swow 兼容性验证后加入）
- Worker 生命周期管理
- 优雅重启
- 数据库/Redis 适配
- 队列和定时任务
- 请求追踪与运行时诊断

## 明确不做

- 自研 ORM
- 自研模板引擎
- 自研 HTTP 客户端
- 自研日志系统
- 自研 RPC 协议
- 一开始就做微服务治理
- 一开始就做插件市场

## 核心设计约束

1. 业务代码不直接依赖 Swoole 或 Swow API。
2. 每个请求必须拥有独立的 Request Context。
3. 应用运行时必须通过 Runtime Adapter 接入，业务代码不直接依赖底层服务器。
4. 优先使用稳定的 PSR 接口和成熟组件。
5. 只有在真实项目中重复出现至少两次的问题，才抽象进框架。
6. 框架要小到一个人可以长期维护。
7. Swoole 的信号和 Worker 生命周期由 Runtime 负责，Application 不感知底层扩展。
8. 请求级对象必须绑定到当前 Context，不能放进 Worker 级单例。
9. 健康检查、readiness 和探针路由属于应用/部署层，不内置进框架核心。

### Bootstrap 配置路径

应用的 `config/autoload.php` 必须由 bootstrap 显式传入 `Application::__construct(autoloadPath: ...)`。
内核不根据 `getcwd()` 推断项目配置，避免 CLI、测试和进程管理器从不同工作目录启动时加载到错误的 Provider 或 binding。

## 第一周验收标准

```text
示例应用的 GET /health 可以返回 JSON
路由和 Middleware 可以工作
Request ID 可以贯穿请求
同一份代码可以通过 Swoole Runtime 运行
连续两个请求之间不能共享业务状态
核心逻辑有自动化测试
```

## 后续演进方向

```text
Application Kernel
        ↓
Runtime Adapter（Swoole / Swow）
        ↓
真实项目验证
        ↓
连接池、队列、观测和 Worker 管理
```

## 当前生产基线

- 错误响应统一为 `{ "error": { "code": "...", "message": "..." } }`，内部异常不直接暴露；
- 路由匹配交给 FastRoute，Magic 只保留 Request/Response、错误响应、HEAD/OPTIONS 和生命周期适配；
- Request ID 只接受受限字符集和长度，正常、异常、短路和停止态响应均会回传；
- 配置通过不可变 `Config` 读取，环境变量只负责本地/部署级参数，不承担配置中心职责；
- 应用 shutdown 先进入 stopping 状态，新的请求返回 503，运行时负责 drain；
- RequestContext 负责请求级状态和结束清理回调；
- Request ID 在 Application 请求准入阶段生成一次，所有响应路径复用同一值；
- stopping 是请求准入边界：已准入请求继续完成，未准入请求统一返回 503；
- RuntimeMetrics 提供当前进程的请求总量、错误量、活跃请求、耗时汇总/峰值和 Worker 计数快照，导出方式由应用决定；
- Swoole Runtime 默认启用 `enable_coroutine=true` 并使用协程级 Context；显式配置的 Context Storage 不会被覆盖，但关闭协程模式会被拒绝；
- 健康检查和 readiness 由应用自行实现，框架不提供内置检查注册器或探针路由；
- Todo SQLite 示例用于验证真实业务链路，不代表框架将内置 ORM 或数据库抽象。

