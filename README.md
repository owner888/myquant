# MyQuant — 跨市场量化研究工作台

基于公共版 `owner888/myphp` 构建的跨平台（macOS / Windows / Linux / iOS / Android）原生 GPU 量化研究与回测终端。

## 核心特性

1. **双市场统一数据源（Option C）**
   - **黄金外汇（Exness Zero）**：内置 `XAUUSDz` / `EURUSDz` / `USDJPYz` 超低摩擦预设（`0.2 bps` 单边），支持一键导入 Exness 官方历史 Tick ZIP（自动按周期聚合成 K 线）或 MT5 OHLC CSV，以及线上现货黄金同步。
   - **加密市场（Binance）**：支持 `BTCUSDT` / `ETHUSDT` / `SOLUSDT` / `PAXGUSDT` 线上实时 K 线拉取与本地 SQLite 缓存（`8.0 bps` 单边真实摩擦）。
2. **正交策略与「以损定量」风控引擎**
   - 快慢 EMA 趋势信号 + Wilder `ATR(14)` 移动硬止损 + 固定风险百分比头寸计算（`净值 × 单笔风险% ÷ ATR止损距离`）。
3. **跨平台 C 原生网格扫描 + `myphp ffigen` 绑定**
   - `native/quant_sweep.c`（纯 C99，无第三方依赖）通过 `./scripts/build_native.sh` 编译至 `resources/<os>-<arch>/`，由 `./vendor/bin/myphp generate .` 自动生成类型安全的 `MyQuant\Native\QuantSweepFfi`。
   - 毫秒级完成上百组三维参数扫描，并内置 **邻域高原 vs 孤岛尖峰过拟合检测**。

## 常用命令

```bash
# 安装公共版 MyPHP 依赖
composer install

# 编译跨平台 C 动态库并生成 FFI 绑定
./scripts/build_native.sh
./vendor/bin/myphp generate .

# 运行无头 UI 与 FFI 自动化测试
php -d ffi.enable=1 tests/ui_test.php

# 启动桌面热重载开发窗口
./vendor/bin/myphp dev .

# 打包独立生产应用
./vendor/bin/myphp build -platform darwin/arm64 .
```
