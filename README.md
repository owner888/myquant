# MyQuant — 跨市场量化研究工作台

基于公共版 `owner888/myphp`（SwiftUI 风格声明式原生 UI + Skia GPU 渲染）与 **C99 零拷贝列式引擎（Columnar SoA + FFI）** 构建的跨平台（macOS / Windows / Linux / iPadOS / iOS / Android）原生量化研究与百万级 K 线回测终端。

---

## 核心特性

1. **双市场统一数据源（Option C）**
   - **黄金外汇（Exness Zero）**：内置 `XAUUSDz` / `EURUSDz` / `USDJPYz` 超低摩擦预设（`0.2 bps` 单边），支持直接导入 Exness 官方历史 Tick ZIP（`Exness_XAUUSD_*.zip`，自动按选定周期聚合成 OHLCV K 线）或标准 MT5 OHLC CSV，并支持线上现货黄金（`PAXGUSDT` 代理）一键同步。
   - **加密市场（Binance）**：支持 `BTCUSDT` / `ETHUSDT` / `SOLUSDT` / `PAXGUSDT` 线上实时 K 线拉取（`5m` / `15m` / `1h` / `4h` / `1d`）与本地 SQLite 事务缓存（默认 `8.0 bps` 单边真实 Taker + 滑点摩擦）。
   - **离线确定性基准行情**：首次离线启动自动生成多阶段（趋势 + 盘整 + 突破）基准行情并落盘 SQLite，无网络依赖即可开箱运行。

2. **正交多空策略与「以损定量」风控引擎**
   - **零未来函数事件驱动（支持多空双向）**：快慢 EMA 趋势信号 + Wilder `ATR(14)` 移动硬止损（含跳空高低点真实滑点撮合），支持 **仅做多（Long）**、**多空双向反转（Both）** 与 **仅做空（Short）**。
   - **固定风险百分比头寸管理（Position Sizing）**：单笔开仓数量由 `净值 × 单笔风险% ÷ ATR止损距离` 反推，并受市场最大杠杆上限约束（黄金外汇 `3.0x`、加密现货 `1.0x`）。
   - **全维度体检与摩擦归因**：实时计算净收益率（对比 Buy & Hold）、Profit Factor、最大回撤、Calmar 比率、单笔期望 R 倍数（Expectancy R）以及手续费吞噬毛利占比告警。

3. **v3 零拷贝 C Columnar SoA 存储 + SQLite 流式落盘 + 实时增量流**
   - 本地大体积 Exness Tick ZIP / MT5 CSV 无条数上限，按 `4096` 根分块流式同步写入 **SQLite 事务仓库** 与 **C 侧列式数组**（`time_ms`, `open`, `high`, `low`, `close`, `volume`, 预计算 `atr`），PHP 堆**零全量 `Candle` 数组驻留**。
   - 支持 **实时增量流**（`mq_dataset_update_last`）：在线拉取最新 K 线或离线微 Tick 演进，按 `O(log N)` 沿 LOD 二叉金字塔右脊实时更新极值与策略净值。
   - 同步构建最高 20 层 `float32` 二叉 OHLC 极值金字塔（采用 `nextafterf` 向上/向下保包络舍入，确保缩略图绝不丢失极值针尖），支持任意视口平移与缩放（`viewStartPct` / `viewSpanPct`），单次 `O(log N)` 视口查询低于 **10 µs**。

4. **多核并行三维网格扫描 +「参数高原 vs 孤岛尖峰」过拟合检测**
   - `native/quant_sweep.c` 基于原生线程池（POSIX `pthread` / Windows `_beginthreadex`，自动检测 CPU 核心数，最多 16 核无锁分片）直接在常驻 SoA 内存上并行扫描 `Fast EMA × Slow EMA × ATR Mult` 三维参数网格。
   - 对每组参数自动评估三维 26 邻域同步盈利比例（`neighborPassPct`），划分为 **高原稳健（≥65%）**、**边缘过渡**、**孤岛过拟合（<35%）** 与 **负期望区**，支持一键将高原参数套用回实验室。

5. **全平台原生视觉与响应式交互**
   - **桌面端**：macOS `hiddenInset` 沉浸式标题栏 + `NSVisualEffect` 毛玻璃侧边栏；Windows 11 原生 Mica 侧边栏（`vibrancy: 'sidebar'`）。
   - **平板与移动端**：基于 `TabViewStyle::sidebarAdaptable`，在 iPadOS / Android Pad 横屏下支持 HIG 侧边栏收起并自动变形为顶部悬浮胶囊导航栏，手机端自适应底部标签栏。

---

## 实测性能基准（Apple Silicon 14 核实测）

| 指标 | 1,000,000 根 K 线压测表现 |
| --- | --- |
| **内存占用** | C SoA + LOD 金字塔共 **62.9 MB** · PHP 堆增量 **< 1.0 MB**（总堆 ~17.8 MB） |
| **O(log N) LOD 视口缩放查询** | **~9.0 µs**（精确极值包络 + 420 桶降采样） |
| **单次百万根完整回测** | **~3.0 ms**（含资金曲线降采样 + 最新闭合交易环形缓冲区导出） |
| **140 组 × 100 万根全量网格扫描** | **~35.9 ms**（14 核并行，合计遍历 1.4 亿根 K 线） |

---

## 目录结构

```text
.
├── app.php                     # 应用入口（窗口尺寸、macOS hiddenInset / Win11 Mica 配置）
├── boot.php                    # 框架加载器（./myphp -> $MYPHP_HOME -> myphp.json framework）+ 内置 PSR-4
├── view.php                    # 声明式跨端 UI（回测实验室 / C 原生参数高原 / 成交账本 / 量化手册）
├── myphp.json                  # MyPHP 打包配置与 ffigen 自动绑定声明
├── native/
│   ├── quant_sweep.h           # C99 引擎头文件（SoA 数据集、LOD 查询、回测与多核扫描 ABI）
│   └── quant_sweep.c           # C99 零依赖实现（Columnar SoA + 二叉 LOD 金字塔 + 多核线程池）
├── resources/                  # 应用图标与按 <os>-<arch> 存放的预编译原生动态库
│   ├── darwin-arm64/libquant_sweep.dylib
│   ├── darwin-amd64/libquant_sweep.dylib
│   └── linux-arm64/libquant_sweep.so
├── scripts/
│   └── build_native.sh         # 跨平台 C 动态库编译脚本（支持 clang / zig cc / mingw / msvc）
├── src/
│   ├── QuantState.php          # 全局响应式状态机（数据源切换、LOD 视口缩放、回测与扫描编排）
│   ├── Data/
│   │   ├── Candle.php          # K 线值对象（仅用于导入/缓存瞬时传输）
│   │   ├── CandleStore.php     # 本地 SQLite K 线持久化仓库
│   │   ├── DatasetHandle.php   # C SoA 数据集句柄管理（RAII 自动释放 + 纯 PHP 降级兜底）
│   │   └── MarketFeed.php      # Binance 在线拉取、Exness Tick ZIP / MT5 CSV 解析与基准数据生成
│   ├── Engine/
│   │   ├── Backtester.php      # 策略回测器（优先走 C SoA 零拷贝内核，无 FFI 时自动回退 PHP）
│   │   └── SweepRunner.php     # 多核参数网格扫描与三维邻域高原稳健度评分
│   └── Native/
│       └── QuantSweepFfi.php   # 由 `myphp generate` 自动生成的类型安全 FFI 绑定类
└── tests/
    └── ui_test.php             # 无头（Headless）桌面/平板 UI 交互 + 100 万根 C SoA 自动化回归测试
```

---

## 常用命令

```bash
# 1. 安装（或升级）全局的 MyPHP，像 Flutter SDK 一样一台机器一份（要求 PHP >= 8.4 且开启 ext-ffi）
curl -fsSL https://myphp.ai/install.sh | sh   # 已安装则: myphp upgrade

# 2. 编译跨平台 C 动态库并重新生成 FFI 绑定
./scripts/build_native.sh
myphp generate .

# 交叉编译指定目标平台（安装了 zig 时可直接在 macOS 交叉编译 Linux / Windows）：
./scripts/build_native.sh darwin-arm64 darwin-amd64 linux-amd64 linux-arm64 windows-amd64

# 3. 运行无头 UI 与 100 万根 K 线 FFI 自动化压测
myphp test

# 4. 启动桌面热重载开发窗口
myphp dev .

# 5. 打包独立桌面 / 移动生产应用
myphp build -platform darwin/arm64 .
```

---

## 环境变量

启动或截图压测时可通过环境变量直达指定工作模式：

| 变量名 | 可选值 / 说明 |
| --- | --- |
| `MYQUANT_SCALE` | `live`（默认，SQLite/实盘行情）、`100k`（启动即在 C 侧生成 10 万根 K 线）、`1m`（启动即生成 100 万根 K 线） |
| `MYQUANT_DIR` | `long`（默认，仅做多）、`both`（多空双向反转）、`short`（仅做空） |
| `MYQUANT_TAB` | `lab`（回测实验室）、`sweep`（C 原生参数高原）、`journal`（成交账本与摩擦）、`guide`（量化手册） |
| `MYPHP_HOME` | MyPHP 框架目录。`myphp` 命令（dev / run / test / screenshot）会自动设置为它自己那份；手动指向另一份检出可做框架联调 |

---

## 本地历史数据导入格式

在「回测实验室」底部点击 **「导入数据」** 按钮（或将文件拖放到应用图标），通过系统原生文件选择器选取文件即可流式写入 SQLite + C SoA：

1. **Exness 官方 Tick 压缩包 / CSV**（如 `Exness_XAUUSD_2026_05.zip`）
   - 首行包含 `Bid` 与 `Ask` 列（格式：`"Exness","Symbol","Timestamp","Bid","Ask"`），导入器会自动取 `(Bid + Ask) / 2` 中间价并按当前选定周期（如 `5m` / `15m` / `1h`）聚合成 OHLCV K 线。
2. **标准 MT5 / 通用 OHLCV CSV**（支持逗号或 Tab 分隔）
   - 列顺序：`time,open,high,low,close[,volume]`，`time` 支持毫秒时间戳、秒级时间戳或 ISO 日期时间字符串。

---

## 架构与维护指南

更底层的 C ABI 内存布局、FFI 数组步长协议与二次开发规范请参阅 [`AGENTS.md`](./AGENTS.md)。
