# Magic Todo API

这是一个使用 Magic Framework 验证真实业务路径的 SQLite API 示例。

当前示例通过 `tests/TodoApiTest.php` 验证业务链路，暂不提供独立 HTTP 入口。

接口：

- `GET /health`
- `GET /ready`
- `GET /todos`
- `GET /todos/{id}`
- `POST /todos`，请求体为 `{"title":"write docs"}`
- `PUT/PATCH /todos/{id}`，请求体为 `{"title":"write docs","completed":true}`
- `DELETE /todos/{id}`

数据库默认写入系统临时目录，可通过 `TODO_DB_PATH` 覆盖。

`/health` 和 `/ready` 是 Todo 应用自己的路由，不是框架内置能力。
`/ready` 直接检查 Todo 数据库，并在非 debug 模式下隐藏底层异常详情。
