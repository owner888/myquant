# AGENTS.md — MyQuant 架构与开发指南

本文档面向参与 MyQuant 开发的工程师与 AI Coding Agent，汇总了核心数据流、C99 Columnar SoA 内存模型、FFI 数组步长契约、UI 规范以及验证工作流。

---

## 1. 架构总览与零拷贝数据流

MyQuant 采用 **PHP 声明式 UI 控制面 + C99 Columnar SoA 计算与存储数据面** 的双层分离架构：

```text
MarketFeed (Binance REST / Exness Tick ZIP / MT5 CSV / Synthetic)
   │
   ▼  瞬时 list<Candle>（仅导入/同步阶段存在，随后立即释放）
CandleStore (SQLite 持久化: UserData/myquant.sqlite)
   │
   ▼  DatasetHandle::fromCandles() 或 DatasetHandle::generateMock()
native/quant_sweep.c (g_datasets[64] 常驻 C 侧列式数组 + Wilder ATR(14) 预计算列 + 20 层 float32 LOD 金字塔)
   ├──► QuantState::updateViewport()  ──► mq_dataset_query_lod()      ──► O(log N) 微秒级图表视口切片
   ├──► Backtester::runOnDataset()    ──► mq_backtest_on_dataset()    ──► 单次零拷贝回测 + 环形缓冲成交账本
   └──► SweepRunner::sweepOnDataset() ──► mq_sweep_on_dataset()       ──► pthread / Win32 多核无锁网格扫描
```

### 关键不变量
1. **PHP 堆零 K 线驻留**：当 `QuantSweepFfi::available()` 为 `true` 时，`DatasetHandle` 只保存整型槽位句柄 `$id`（`1..64`）、`$count` 和 `$cMemoryMb`，不保留 `$fallbackCandles`。切换品种/周期或销毁 `DatasetHandle` 时必须调用 `release()`（析构函数已兜底调用 `mq_dataset_free`），防止 64 个静态槽位泄漏。
2. **纯 PHP 无缝降级**：当运行环境未开启 `ext-ffi` 或缺少对应平台动态库时，`DatasetHandle` 自动回退至 `$fallbackCandles`，`Backtester::run()` 与 `SweepRunner::sweep()` 保持与 C 内核一致的撮合与指标口径。
3. **异步请求防竞态**：`QuantState::loadDataset(true)` 在 `App::go(...)` 协程中拉取线上 K 线时，通过递增序号 `$this->seq` 校验结果新鲜度，用户快速切换品种不会导致旧响应覆盖新状态。

---

## 2. C99 内核与 FFI 协议契约（`native/quant_sweep.h`）

> **注意**：`src/Native/QuantSweepFfi.php` 由 `./vendor/bin/myphp generate .` 根据 `myphp.json` 中的 `ffigen` 配置自动生成，**请勿手动编辑该文件**。

### 2.1 版本与常量
- `MQ_SWEEP_VERSION = 3`
- `MQ_ATR_PERIOD = 14`（数据集分配、流式 `finalize` 或更新最后一根 K 线时，C 侧自动计算 Wilder ATR(14)，供回测与多线程扫描共享只读复用）
- `MQ_MAX_DATASETS = 64`（静态表槽位，`ds_id` 为 `slot_index + 1`，支持 `realloc` 动态扩容）
- 方向常量：`MQ_DIR_LONG = 1`（仅做多）、`MQ_DIR_BOTH = 0`（多空双向反转）、`MQ_DIR_SHORT = -1`（仅做空）
- 流式导入与增量接口：`mq_dataset_create_empty` → `mq_dataset_append_batch` → `mq_dataset_finalize`（配合 `CandleStore::saveRawBatch` / `loadDataset` 分批读写 SQLite），以及 `mq_dataset_get_last` / `mq_dataset_update_last`（`O(log N)` 右脊实时流更新）

### 2.2 二叉 OHLC LOD 金字塔（`mq_dataset_query_lod`）
- 第 `0` 层（`shift = 2`）每桶聚合 `4` 根原始 K 线，之后每层二合一（`shift = lv + 2`），最多 `20` 层。
- 金字塔使用 `float` 存储以节省一半带宽，其中 `high` 通过 `nextafterf(f, +FLT_MAX)` 向上舍入、`low` 通过 `nextafterf(f, -FLT_MAX)` 向下舍入，严格保证任意缩放层级下的极值包络 `out_extremes[0..1]`（`[low, high]`）绝不截断真实影线极值。

### 2.3 单次回测输出布局（`mq_backtest_on_dataset`）
- **`out_summary`（`double[14]`，`MQ_BACKTEST_SUMMARY_LEN = 14`）**：
  | 下标 | 字段含义 |
  | --- | --- |
  | `0` | `finalEquity`（期末净值） |
  | `1` | `netReturnPct`（策略净收益率 %） |
  | `2` | `buyHoldReturnPct`（Buy & Hold 收益率 %） |
  | `3` | `maxDrawdownPct`（最大回撤正数百分比 %） |
  | `4` | `calmarRatio`（Calmar 比率） |
  | `5` | `profitFactor`（获利因子 `grossWin / grossLoss`） |
  | `6` | `winRatePct`（胜率 %） |
  | `7` | `expectancyR`（单笔期望 R 倍数） |
  | `8` | `closedTrades`（总闭合交易笔数） |
  | `9` | `totalFeesQuote`（累计双边手续费支出） |
  | `10` | `grossProfitQuote`（累计毛利） |
  | `11` | `grossLossQuote`（累计毛亏正数值） |
  | `12` | `curveLen`（实际写入曲线点数） |
  | `13` | `exportedTrades`（实际导出的最新交易笔数，`<= max_trades`） |

- **`out_trades`（每笔步长 `MQ_TRADE_STRIDE = 8`，按时间倒序导出最新 `exportedTrades` 笔）**：
  | 偏移 | 字段含义 |
  | --- | --- |
  | `+0` | 交易序号 `id`（`1..closed`） |
  | `+1` | 开仓时间戳 `entry_time_ms` |
  | `+2` | 平仓时间戳 `exit_time_ms` |
  | `+3` | 开仓价 `entry_price` |
  | `+4` | 平仓价 `exit_price` |
  | `+5` | 净盈亏 `pnl`（已扣手续费） |
  | `+6` | 实现风险倍数 `r_multiple` |
  | `+7` | 方向与平仓原因编码（`+1` = 多单 ATR 硬止损，`+2` = 多单 EMA 趋势反转，`-1` = 空单 ATR 硬止损，`-2` = 空单 EMA 趋势反转） |

### 2.4 多核网格扫描输出布局（`mq_sweep_on_dataset`）
- 线程池策略：当 `n_candles >= 2000` 且组合数 `>= 4` 时自动启用多线程（主线程兼任最后一个分片 worker，避免空转等待），各线程写入各自独立的 `out_metrics[t * 8]` 区间，全程无锁。
- **`out_metrics`（每组步长 `MQ_METRICS_STRIDE = 8`）**：
  `[0] fast_p`, `[1] slow_p`, `[2] atr_mult`, `[3] net_return_pct`, `[4] max_dd_pct`, `[5] profit_factor`, `[6] win_rate_pct`, `[7] closed_trades`。

---

## 3. 启动加载链与 UI 约定

1. **`boot.php` 加载优先级**
   - 先注册内置 `MyQuant\` -> `src/` 的轻量 PSR-4 自动加载器（即使没有 `vendor/` 也能工作）。
   - 框架加载顺序：`./myphp`（打包独立产物） → `$MYPHP_HOME`（本地框架开发/截图 CLI） → `myphp.json` 中的 `framework` 配置 → `./vendor/autoload.php`。**切勿颠倒 `$MYPHP_HOME` 与 `vendor/` 的优先级**，否则框架侧升级特性在联调时会被锁定的旧 `vendor/` 遮蔽。
2. **`view.php` 跨端布局规范**
   - 顶层采用 `TabView(...)->tabViewStyle(TabViewStyle::sidebarAdaptable)`，不要手写自定义侧边栏替代系统自适应导航，以保持 macOS 毛玻璃侧边栏、Windows 11 Mica 侧边栏、iPadOS/Android Pad 横屏收起侧边栏自动变形为悬浮胶囊栏的原生行为。
   - 顶部选择器（市场、规模、品种、周期）分为上下两行均衡 `HStack` 排列，防止在窄屏或平板分屏下发生水平裁切。

---

## 4. 修改代码后的标准验证流程

### 修改了 `native/quant_sweep.h` 或 `native/quant_sweep.c` 时：
```bash
# 1. 重新编译当前宿主（或全目标平台）动态库至 resources/<os>-<arch>/
./scripts/build_native.sh

# 若本机安装了 zig，可一并刷新 Linux / Windows 预编译产物：
# ./scripts/build_native.sh darwin-arm64 darwin-amd64 linux-arm64 linux-amd64 windows-amd64

# 2. 重新生成 PHP FFI 绑定类（若修改了 quant_sweep.h 签名或宏常量）
./vendor/bin/myphp generate .

# 3. 运行无头全链路回归测试（校验 FFI 版本、100 万根 SoA 堆增量 <1MB、LOD <1ms 及平板侧边栏变形）
php -d ffi.enable=1 tests/ui_test.php
```

### 修改了 PHP 业务或 `view.php` UI 时：
```bash
# 必须保证 strict 模式下的桌面与平板 Headless 测试 0 失败
php -d ffi.enable=1 tests/ui_test.php
```

### 每新增功能或修改跨端 UI / 内核后的四端标准验收流程

每次新增功能或改动跨平台交互后，Coding Agent 应按以下顺序完成四端回归，不手跑底层 `xcrun` / `adb` 脚本：

1. **通用无头回归（`< 100ms`，每次必跑）**
   - 对所有改动 PHP 文件执行 `php -l`，再运行 `php -d ffi.enable=1 tests/ui_test.php`（同时覆盖桌面端与平板端 `strict` Key 检查、FFI 版本校验、100 万根 K 线 SoA 堆增量 `< 1MB` 及无 FFI 纯 PHP 降级）。
2. **macOS（Metal + 毛玻璃侧边栏）**
   - 桌面与移动视口截图走查：
     ```bash
     ./vendor/bin/myphp screenshot -size 1280x800 -theme dark -out /tmp/myquant-macos.png .
     ./vendor/bin/myphp screenshot -size 402x874 -theme light -out /tmp/myquant-mobile.png .
     ```
   - 热重载或打包校验：`./vendor/bin/myphp run -d macos .`、`./vendor/bin/myphp build -platform darwin/arm64 .`。
3. **Windows（本地 Windows VM，通过免密 SSH 别名如 `ssh win` 联调）**
   - **为什么在 Windows 内原生编译与截图**：`myphp build` 的 Windows 产物需直接携带宿主 Windows 的 `php.exe` 与 `ext/*.dll`，并调用本地 C 编译器（`gcc` / `clang` / `cl`）生成无控制台 GUI 启动器 `MyQuant.exe`，不支持从 macOS 交叉打包整个 Windows 应用。因此在装有 PHP 8.4/8.5（开启 `ffi`、`sqlite3`、`pdo_sqlite`、`curl`、`mbstring`、`openssl`）与轻量 MinGW/LLVM-MinGW 的 Windows VM 内完成编译、测试与截图。
   - **代码同步与 SSH 无头测试 / 打包**：将宿主改动同步至 VM 工作目录后执行：
     ```bash
     ssh win "cd %USERPROFILE%\myquant && php -d ffi.enable=1 tests\ui_test.php"
     ssh win "cd %USERPROFILE%\myquant && ..\myphp\bin\myphp.cmd build -skip-installer ."
     ```
   - **D3D11 + Win11 Mica 真实窗口截图回传**：OpenSSH 会话默认位于后台非交互会话（Session 0），无法直接访问桌面 DWM；通过计划任务 `/IT` 在已登录交互桌面触发 `myphp.cmd screenshot`，再拉回宿主机做视觉检查：
     ```bash
     ssh win "schtasks /Create /TN MyQuantShot /TR \"cmd /c cd /d %USERPROFILE%\myquant && ..\myphp\bin\myphp.cmd screenshot -size 1280x800 -theme dark -out C:\Windows\Temp\myquant-win.png .\" /SC ONCE /ST 00:00 /IT /RL HIGHEST /F && schtasks /Run /TN MyQuantShot"
     scp win:C:/Windows/Temp/myquant-win.png /tmp/myquant-win.png
     ```
4. **iOS / iPadOS（Metal + UIKit 安全区与自适应导航）**
   - 查看可用设备：`./vendor/bin/myphp devices`（首次跑模拟器前确保宿主已安装 `autoconf automake libtool pkg-config bison re2c` 以构建模拟器 `libphp.a`）。
   - 启动并检查日志：`./vendor/bin/myphp run -d ios .`，重点验证灵动岛/底部安全区、横竖屏旋转及 iPad 横屏侧边栏 ↔ 竖屏顶部悬浮胶囊栏变形；发版构建校验：`./vendor/bin/myphp build -platform ios/arm64 .`。
5. **Android（EGL / GLES 3 + 16KB 页对齐与 Enforcing SELinux）**
   - 启动 `SELinux = Enforcing` 标准镜像模拟器（`./vendor/bin/myphp emulators --launch <id>`）或连接非 root 真机。
   - 安装运行并流式检查日志：`./vendor/bin/myphp run -d android .`（确认无 `avc: denied` 或 PHP 告警）；发版构建校验：`./vendor/bin/myphp build -platform android/arm64 .`（若附带 `android/jniLibs/arm64-v8a/libquant_sweep.so`，编译时必须传入 `-Wl,-z,max-page-size=16384`）。
