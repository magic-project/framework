# Magic Framework 路线图

## 第一周：最小应用闭环

目标：同一份业务代码能在 Swoole 长驻进程下运行，并且请求状态不会泄漏。

- [x] 初始化 Composer 项目
- [x] 配置 PSR-4 自动加载
- [x] 配置 PHPUnit
- [x] 实现 Application
- [x] 实现最小 Container
- [x] 实现 RequestContext
- [x] 实现 Request/Response
- [x] 实现 Router
- [x] 实现 Middleware
- [x] 实现 Request ID
- [x] 在 Todo 示例中实现 `/health`
- [x] 编写请求状态隔离测试
- [x] 编写路由参数测试

## PHP/Runtime 兼容矩阵

| 组件 | 当前策略 |
|---|---|
| PHP | 8.4–8.5 |
| PHP 8.2/8.3 | 不支持 |
| PHP 8.6 | 稳定版发布并完成运行时验证后加入 |
| Swoole | 作为可选 Runtime Adapter |
| Swow | 作为可选 Runtime Adapter |

## 第二周：Swoole Runtime

- [x] 定义 RuntimeInterface
- [x] 实现 Worker 启动和停止
- [x] 把请求分发到 Application
- [x] 支持优雅退出
- [x] 增加长驻进程状态隔离测试
- [x] 编写 Swoole 本地启动示例

## 第三至四周：应用基础能力

### 第三周：请求生命周期和资源作用域

- [x] RequestLifecycle
- [x] Request Start/End Hooks
- [x] RequestContext::defer()
- [x] 异常路径资源清理

### 第四周：应用基础能力

- [x] 使用 FastRoute 完善 Router HTTP 方法、404/405、Allow、HEAD/OPTIONS 契约
- [x] 异常处理
- [x] 配置加载
- [x] 环境变量
- [x] 统一日志入口（PSR-3）
- [x] CLI 命令通用抽象（命令注册、参数解析、输出和退出码）
- [x] 在 Todo 示例中实现应用层健康/就绪路由（框架不内置检查器）
- [x] 统一错误响应
- [x] 请求体/请求头大小限制
- [x] Swoole shutdown timeout 映射与停止失败日志
- [x] CI lint/test 检查

## P0/P1/P2 交付状态

### P0：生产安全基线（已落地）

- 配置对象支持环境变量、dot notation 和必填项校验；
- PSR-3 JSON 结构化日志，异常日志自动关联 Request ID；
- 统一 JSON 错误结构，安全隐藏内部异常；
- Todo 示例提供 `/health` 和 `/ready`，readiness 由应用路由自行检查并返回 503；
- Request ID 格式/长度校验、异常响应保留 Request ID；
- 请求体/请求头大小限制，413/431 契约；
- PHP 8.4/8.5 CI、Composer 校验、全量 lint 和 PHPUnit。

### P1：运行时与资源生命周期（已落地基础契约）

- Application boot/shutdown、worker start/stop hooks；
- shutdown hook 逆序执行、异常聚合、幂等；
- Swoole 信号停止、请求停止态 503、worker 生命周期回调；
- worker hook 异常继续执行并聚合后抛出首个异常；
- Swoole Runtime 明确 `enable_coroutine=true` 与 Context Storage 配置契约；
- Swoole 请求隔离、嵌套保护和异常清理测试。

真实 TCP/多 worker 请求隔离和主进程 SIGTERM 停止已通过本地验证；仍需在真实部署中补充：Swoole socket 压测、worker drain/超时、容器编排探针和多进程故障演练。

### P2：真实项目验证（已落地最小闭环）

- SQLite Todo API 示例；
- GET/POST/PUT/PATCH/DELETE、JSON 校验、404/415/422，以及应用层 readiness 路由；
- 业务仓储、内存 SQLite 自动化测试。

仍需按真实项目重复需求再决定是否抽取数据库连接池、Redis、队列和定时任务，当前不将它们提前塞入核心。

## 第五至六周：真实项目验证

- [x] 使用框架开发一个真实 API 项目
- [x] 记录重复出现的基础设施问题
- [x] 只抽取至少出现两次的问题
- [x] 增加真实 Swoole TCP/多 worker 请求隔离集成测试
- [ ] 真实部署中的 worker drain/超时和故障演练

## 第七至八周：连接和任务能力

- [ ] 数据库适配
- [ ] Redis 适配
- [ ] 队列抽象
- [ ] 定时任务
- [ ] 连接池生命周期管理

## 第九至十周：诊断和可观测性

- [x] 请求耗时
- [x] 请求 ID
- [x] Worker 状态
- [ ] 内存变化
- [ ] 慢请求记录
- [ ] 异常和 Trace 信息

## 第十一至十二周：Swow 和发布

- [ ] 抽象 Runtime Adapter
- [ ] 实现 Swow Adapter
- [ ] 增加项目模板
- [ ] 完善文档
- [ ] 发布 0.1.0

## 暂不实现

- ORM
- 模板引擎
- RPC
- 微服务治理
- 注解系统
- 配置中心
- 插件市场

