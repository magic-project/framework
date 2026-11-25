# 兼容性策略

## PHP

当前生产支持范围：

```text
PHP 8.4
PHP 8.5
```

PHP 8.2 和 PHP 8.3 不在支持范围内。PHP 8.6 当前不纳入生产兼容承诺，等稳定版发布，并完成 Swoole/Swow 运行时测试后再加入。

## Runtime

Swoole 作为当前 Runtime Adapter，Swow 作为后续 Runtime Adapter；两者都通过 Runtime Adapter 与应用核心解耦。

目标运行时：

```text
Swoole
Swow
```

Runtime Adapter 必须遵守以下原则：

1. 业务代码不能直接依赖 Swoole 或 Swow API。
2. 同一份 Application 代码应能在不同 Runtime 下运行。
3. 每个 Runtime 都要有独立的集成测试。
4. 新 PHP 小版本发布后，先验证 Runtime，再更新兼容矩阵。

