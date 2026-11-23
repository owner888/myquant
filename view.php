<?php
// SwiftUI-standard declarative UI for MyQuant ($this is MyQuant\QuantState).
// Adapts seamlessly across macOS, Windows, Linux, iPadOS, Android Pad, and iPhone.
declare(strict_types=1);

use MyQuant\Data\MarketFeed;

$accent = Color::rgb(0x10B981);
$green = Color::rgb(0x10B981);
$cyan = Color::rgb(0x38BDF8);
$amber = Color::rgb(0xF59E0B);
$red = Color::rgb(0xEF4444);

$card = fn(View ...$children) => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(...$children)
    ->padding(16)
    ->frame(maxWidth: INF, alignment: Alignment::topLeading)
    ->background(Color::gray->opacity(0.09))
    ->cornerRadius(12);

$metricTile = fn(string $label, string $value, string $sub, mixed $tone = null) => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
    Text($label)->font(Font::caption)->foregroundStyle(Color::secondary),
    Text(verbatim: $value)->font(Font::system(size: 21, weight: Weight::bold))->foregroundStyle($tone ?? Color::primary),
    Text(verbatim: $sub)->font(Font::caption2)->foregroundStyle(Color::secondary),
)->padding(14)->frame(maxWidth: INF, alignment: Alignment::topLeading)->background(Color::gray->opacity(0.09))->cornerRadius(12);

$marketBinding = new Binding(
    get: fn() => $this->market,
    set: fn(string $m) => $this->switchMarket($m),
);
$symbolBinding = new Binding(
    get: fn() => $this->symbol,
    set: fn(string $s) => $this->selectSymbol($s),
);
$intervalBinding = new Binding(
    get: fn() => $this->interval,
    set: fn(string $iv) => $this->selectInterval($iv),
);
$scaleBinding = new Binding(
    get: fn() => $this->scaleMode,
    set: fn(string $sm) => $this->selectScaleMode($sm),
);

$marketPicker = fn() => Picker('市场', selection: $marketBinding)(
    Text('黄金外汇 · Exness')->tag('forex'),
    Text('加密市场 · Binance')->tag('crypto'),
)->pickerStyle(PickerStyle::segmented);

$symbolPicker = fn() => Picker('品种', selection: $symbolBinding)(
    Each($this->currentSymbols())(fn(string $sym) => Text(verbatim: $sym)->tag($sym))
)->pickerStyle(PickerStyle::segmented);

$intervalPicker = fn() => Picker('周期', selection: $intervalBinding)(
    Each(MarketFeed::INTERVALS)(fn(string $iv) => Text(verbatim: $iv)->tag($iv))
)->pickerStyle(PickerStyle::segmented);

$scalePicker = fn() => Picker('规模', selection: $scaleBinding)(
    Text('标准行情')->tag('live'),
    Text('10万根 SoA')->tag('100k'),
    Text('100万根 SoA')->tag('1m'),
)->pickerStyle(PickerStyle::segmented);

$scopeBar = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    HStack(alignment: VerticalAlignment::center, spacing: 12)(
        $marketPicker(),
        Spacer(),
        $scalePicker(),
    ),
    HStack(alignment: VerticalAlignment::center, spacing: 12)(
        $symbolPicker(),
        Spacer(),
        $intervalPicker(),
    ),
);

$syncToolbar = fn() => [
    Button(
        $this->syncing ? '同步中…' : '同步实盘行情',
        action: fn() => $this->loadDataset(true),
        systemImage: 'arrow.clockwise',
    )->disabled($this->syncing),
];

$labPage = function () use ($card, $metricTile, $scopeBar, $syncToolbar, $accent, $green, $cyan, $amber, $red): View {
    $r = $this->report;
    $lod = $this->lodView;
    $ret = (float)($r['netReturnPct'] ?? 0.0);
    $bh = (float)($r['buyHoldReturnPct'] ?? 0.0);
    $pf = (float)($r['profitFactor'] ?? 0.0);
    $dd = (float)($r['maxDrawdownPct'] ?? 0.0);
    $calmar = (float)($r['calmarRatio'] ?? 0.0);
    $expR = (float)($r['expectancyR'] ?? 0.0);
    $wr = (float)($r['winRatePct'] ?? 0.0);
    $trades = (int)($r['closedTrades'] ?? 0);
    $fees = (float)($r['totalFeesQuote'] ?? 0.0);
    $btMs = (float)($r['elapsedMs'] ?? 0.0);

    $lodLow = (float)($lod['low'] ?? 0.0);
    $lodHigh = (float)($lod['high'] ?? 1.0);
    $lodPad = max(0.0001, ($lodHigh - $lodLow) * 0.06);

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $scopeBar(),
            Text(verbatim: $this->statusText)->font(Font::caption)->foregroundStyle(Color::secondary),
            LazyVGrid(columns: [GridItem::adaptive(minimum: 210)], spacing: 12)(
                $metricTile(
                    '策略净收益 (扣摩擦)',
                    sprintf('%+.2f%%', $ret),
                    sprintf('买死不动 B&H: %+.2f%% · 回测 %.2f ms', $bh, $btMs),
                    $ret >= 0 ? $green : $red,
                ),
                $metricTile(
                    'Profit Factor (获利因子)',
                    sprintf('%.2f', $pf),
                    $pf >= 1.5 ? '优秀 (>1.5 正期望稳健)' : ($pf >= 1.05 ? '微利边缘 (注意滑点损耗)' : '负期望 (<1.0 禁止实盘)'),
                    $pf >= 1.5 ? $green : ($pf >= 1.05 ? $amber : $red),
                ),
                $metricTile(
                    '最大回撤 / Calmar',
                    sprintf('-%.2f%%', $dd),
                    sprintf('收益回撤比 Calmar: %.2f', $calmar),
                    $dd <= 15.0 ? $green : $amber,
                ),
                $metricTile(
                    '单笔期望 (R) / 交易样本',
                    sprintf('%+.2f R', $expR),
                    sprintf('胜率 %.1f%% · %s 笔 · 摩擦 $%.0f', $wr, number_format($trades), $fees),
                    $expR > 0 ? $green : $red,
                ),
            ),
            $card(
                HStack(alignment: VerticalAlignment::firstTextBaseline)(
                    Text(verbatim: sprintf('C 原生 LOD 金字塔行情视野 (%s 根 K 线)', number_format($this->barCount)))->font(Font::headline),
                    Spacer(),
                    Text(verbatim: sprintf(
                        'O(log N) 极值 [%.2f .. %.2f] · %d 桶 · 查询 %.1f µs · C %.1f MB / PHP %.1f MB',
                        $lodLow,
                        $lodHigh,
                        (int)($lod['buckets'] ?? 0),
                        (float)($lod['elapsedUs'] ?? 0.0),
                        $this->cMemoryMb,
                        $this->phpMemoryMb,
                    ))->font(Font::caption)->foregroundStyle(Color::secondary),
                ),
                Chart()(
                    LinePlot(x: $lod['xs'] ?? [0.0, 1.0], y: $lod['closes'] ?? [1.0, 1.0])
                        ->foregroundStyle($cyan),
                )
                    ->chartYScale(domain: [$lodLow - $lodPad, $lodHigh + $lodPad])
                    ->chartYAxis(new AxisMarks(desiredCount: 4, format: fn(float $v) => $v >= 100 ? sprintf('%.0f', $v) : sprintf('%.3f', $v)))
                    ->frame(height: 155),
                LazyVGrid(columns: [GridItem::adaptive(minimum: 260)], spacing: 16)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                        Text(verbatim: sprintf('视野起点偏移: %.1f%%', $this->viewStartPct))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->viewStartPct, in: [0.0, 99.0], step: 0.5, onEditingChanged: fn(bool $e) => !$e && $this->updateViewport())
                            ->onChange(of: $this->viewStartPct, action: fn() => $this->updateViewport()),
                    ),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
                        Text(verbatim: sprintf('视野缩放跨度: %.1f%% (%s 根可见)', $this->viewSpanPct, number_format((int)round($this->barCount * $this->viewSpanPct / 100.0))))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->viewSpanPct, in: [1.0, 100.0], step: 0.5, onEditingChanged: fn(bool $e) => !$e && $this->updateViewport())
                            ->onChange(of: $this->viewSpanPct, action: fn() => $this->updateViewport()),
                    ),
                ),
            ),
            $card(
                HStack(alignment: VerticalAlignment::firstTextBaseline)(
                    Text('策略净值曲线（绿） vs 买死不动基准（灰）')->font(Font::headline),
                    Spacer(),
                    Text(verbatim: sprintf('Fast %.0f / Slow %.0f / ATR %.1fx · 单笔风险 %.1f%% · 摩擦 %.1f bps', $this->fastEma, $this->slowEma, $this->atrMult, $this->riskPct, $this->feeBps))
                        ->font(Font::caption)->foregroundStyle(Color::secondary),
                ),
                Chart()(
                    LinePlot(x: $r['xs'] ?? [0.0, 1.0], y: $r['holdYs'] ?? [10000.0, 10000.0])
                        ->foregroundStyle(Color::secondary->opacity(0.55)),
                    LinePlot(x: $r['xs'] ?? [0.0, 1.0], y: $r['strategyYs'] ?? [10000.0, 10000.0])
                        ->foregroundStyle($accent),
                )
                    ->chartYScale(domain: [(float)($r['yMin'] ?? 9500.0), (float)($r['yMax'] ?? 10500.0)])
                    ->chartYAxis(new AxisMarks(desiredCount: 5, format: fn(float $v) => sprintf('$%.0f', $v)))
                    ->frame(height: 195),
                Divider(),
                Text('水下回撤曲线 (Underwater Drawdown %)')->font(Font::subheadline)->foregroundStyle(Color::secondary),
                Chart()(
                    LinePlot(x: $r['xs'] ?? [0.0, 1.0], y: $r['drawdownYs'] ?? [0.0, 0.0])
                        ->foregroundStyle($red),
                )
                    ->chartYScale(domain: [(float)($r['ddMin'] ?? -10.0), 0.5])
                    ->chartYAxis(new AxisMarks(desiredCount: 4, format: fn(float $v) => sprintf('%.1f%%', $v)))
                    ->frame(height: 90),
            ),
            $card(
                Text('正交策略参数与「以损定量」风控调节')->font(Font::headline),
                LazyVGrid(columns: [GridItem::adaptive(minimum: 240)], spacing: 16)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('快线 EMA 周期: %.0f', $this->fastEma))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->fastEma, in: [4.0, 35.0], step: 1.0, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    ),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('慢线 EMA 周期: %.0f', $this->slowEma))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->slowEma, in: [15.0, 120.0], step: 1.0, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    ),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('ATR 硬止损倍数: %.1f x', $this->atrMult))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->atrMult, in: [1.0, 4.0], step: 0.25, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    ),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('单笔最大允许亏损 (以损定量): %.1f%%', $this->riskPct))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->riskPct, in: [0.25, 3.0], step: 0.25, onEditingChanged: fn(bool $e) => !$e && $this->recomputeAll()),
                    ),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('单边交易摩擦 (手续费+滑点): %.1f bps (%.3f%%)', $this->feeBps, $this->feeBps * 0.01))->font(Font::caption)->foregroundStyle(Color::secondary),
                        Slider(value: $this->feeBps, in: [0.0, 15.0], step: 0.2, onEditingChanged: fn(bool $e) => !$e && $this->recomputeAll()),
                    ),
                ),
                Divider(),
                HStack(alignment: VerticalAlignment::center, spacing: 10)(
                    TextField('导入本地 Exness Tick ZIP 或 MT5 CSV 路径…', text: $this->importPath)
                        ->onSubmit(fn() => $this->importLocalFile()),
                    Button('导入数据', action: fn() => $this->importLocalFile(), systemImage: 'tray.and.arrow.down')
                        ->buttonStyle(ButtonStyle::bordered),
                ),
            ),
        )->padding(Edge::horizontal, 20)->padding(Edge::bottom, 20)->padding(Edge::top, 8)
    )->navigationTitle('回测实验室')->toolbar(...$syncToolbar());
};

$sweepPage = function () use ($card, $metricTile, $scopeBar, $syncToolbar, $green, $amber, $red): View {
    $sw = $this->sweep;
    $usedC = (bool)($sw['usedNativeC'] ?? false);
    $rows = $sw['rows'] ?? [];
    $totalEvalBars = $this->barCount * (int)($sw['totalCombos'] ?? 0);

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $scopeBar(),
            LazyVGrid(columns: [GridItem::adaptive(minimum: 220)], spacing: 12)(
                $metricTile(
                    'SoA 多核并行引擎',
                    $usedC ? 'C SoA + Pthreads' : 'PHP Fallback',
                    sprintf('%s · 耗时 %.2f ms', (string)($sw['libraryPath'] ?? ''), (float)($sw['elapsedMs'] ?? 0.0)),
                    $usedC ? $green : $amber,
                ),
                $metricTile(
                    '扫描吞吐规模',
                    sprintf('%d 组参数', (int)($sw['totalCombos'] ?? 0)),
                    sprintf('遍历 %.2f 亿根 K 线 · 正收益 %d 组', $totalEvalBars / 1e8, (int)($sw['profitableCombos'] ?? 0)),
                ),
                $metricTile(
                    '高原稳健区 (防过拟合)',
                    sprintf('%d 组', (int)($sw['plateauCombos'] ?? 0)),
                    '周围 >=65% 邻格均保持正期望',
                    (int)($sw['plateauCombos'] ?? 0) > 0 ? $green : $red,
                ),
            ),
            $card(
                HStack(alignment: VerticalAlignment::center)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                        Text('三维参数高原排行榜 (Fast EMA × Slow EMA × ATR 倍数)')->font(Font::headline),
                        Text('零拷贝直读 C 端 SoA 列内存与预计算 ATR 列，多核无锁分片扫描。')->font(Font::caption)->foregroundStyle(Color::secondary),
                    ),
                    Spacer(),
                    Button('重新扫描网格', action: fn() => $this->runSweep(), systemImage: 'bolt.fill')
                        ->buttonStyle(ButtonStyle::borderedProminent),
                ),
                Divider(),
                Each($rows, id: 'id')(function (array $row) use ($green, $amber, $red): View {
                    $badgeColor = match ($row['regime']) {
                        '高原稳健' => $green,
                        '孤岛过拟合' => $red,
                        '边缘过渡' => $amber,
                        default => Color::secondary,
                    };
                    return HStack(alignment: VerticalAlignment::center, spacing: 12)(
                        Text(verbatim: sprintf('EMA(%d, %d) · ATR %.1fx', $row['fast'], $row['slow'], $row['atrMult']))
                            ->font(Font::system(size: 13, weight: Weight::semibold))
                            ->frame(width: 175, alignment: Alignment::leading),
                        Text(verbatim: $row['regime'])
                            ->font(Font::caption)
                            ->foregroundStyle($badgeColor)
                            ->frame(width: 80, alignment: Alignment::leading),
                        Text(verbatim: sprintf('邻格通过 %.0f%%', $row['neighborPassPct']))
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary)
                            ->frame(width: 95, alignment: Alignment::leading),
                        Text(verbatim: sprintf('收益 %+.1f%%', $row['netReturnPct']))
                            ->font(Font::caption)
                            ->foregroundStyle($row['netReturnPct'] >= 0 ? $green : $red)
                            ->frame(width: 95, alignment: Alignment::leading),
                        Text(verbatim: sprintf('PF %.2f · 回撤 -%.1f%% · %s笔', $row['profitFactor'], $row['maxDrawdownPct'], number_format($row['trades'])))
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary),
                        Spacer(),
                        Button('套用', action: fn() => $this->applySweepRow($row['fast'], $row['slow'], $row['atrMult']))
                            ->buttonStyle(ButtonStyle::bordered),
                    )->padding(Edge::vertical, 4);
                }),
            ),
        )->padding(Edge::horizontal, 20)->padding(Edge::bottom, 20)->padding(Edge::top, 8)
    )->navigationTitle('C 原生参数高原')->toolbar(...$syncToolbar());
};

$journalPage = function () use ($card, $scopeBar, $syncToolbar, $green, $red): View {
    $r = $this->report;
    $trades = $r['trades'] ?? [];
    $totalClosed = (int)($r['closedTrades'] ?? count($trades));
    $grossWin = (float)($r['grossProfitQuote'] ?? 0.0);
    $fees = (float)($r['totalFeesQuote'] ?? 0.0);
    $feeShare = $grossWin > 1.0 ? ($fees / $grossWin * 100.0) : 0.0;

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $scopeBar(),
            $card(
                Text('交易摩擦与归因体检')->font(Font::headline),
                Text(verbatim: sprintf(
                    '总毛利: $%.2f  |  总毛亏: -$%.2f  |  累计摩擦支出(手续费+滑点): $%.2f (占毛利 %.1f%%)',
                    $grossWin,
                    (float)($r['grossLossQuote'] ?? 0.0),
                    $fees,
                    $feeShare
                ))->font(Font::subheadline)->foregroundStyle($feeShare > 35.0 ? $red : $green),
                Text($feeShare > 35.0
                    ? '警告：当前周期下手续费吞噬了超过 35% 的毛利！请放大 K 线周期（如 4h/1d）或切换到低摩擦市场（如 Exness Zero 黄金）。'
                    : '摩擦占比健康：当前周期波动幅度足以覆盖进出交易成本。'
                )->font(Font::caption)->foregroundStyle(Color::secondary),
            ),
            $card(
                Text(verbatim: sprintf('历史闭合交易明细（最新 %d / 共 %s 笔）', count($trades), number_format($totalClosed)))->font(Font::headline),
                $trades === []
                    ? ContentUnavailableView('暂无闭合交易', systemImage: 'tray', description: '请调整参数或切换周期运行回测。')
                    : Each($trades, id: 'id')(fn(array $t) => HStack(alignment: VerticalAlignment::center, spacing: 10)(
                        Text(verbatim: sprintf('#%d', $t['id']))->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 56, alignment: Alignment::leading),
                        Text(verbatim: "{$t['entryTime']} → {$t['exitTime']}")->font(Font::caption)->frame(width: 175, alignment: Alignment::leading),
                        Text(verbatim: sprintf('入 %.2f / 出 %.2f', $t['entryPrice'], $t['exitPrice']))->font(Font::caption)->frame(width: 175, alignment: Alignment::leading),
                        Text(verbatim: $t['reason'])->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 95, alignment: Alignment::leading),
                        Spacer(),
                        Text(verbatim: sprintf('%+.2f R  (%+.2f$)', $t['rMultiple'], $t['pnl']))
                            ->font(Font::system(size: 13, weight: Weight::semibold))
                            ->foregroundStyle($t['pnl'] >= 0 ? $green : $red),
                    )->padding(Edge::vertical, 4)),
            ),
        )->padding(Edge::horizontal, 20)->padding(Edge::bottom, 20)->padding(Edge::top, 8)
    )->navigationTitle('成交账本与摩擦')->toolbar(...$syncToolbar());
};

$guidePage = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
        $card(
            Text('量化交易五条铁律（实战检验清单）')->font(Font::title3),
            Text('1. 先算摩擦账再选周期：币圈合约单边 8 bps 适合 4h/1d 趋势；零点差黄金 XAUUSDz 单边 0.2 bps 才具备 5m/15m 日内交易的物理基础。')->font(Font::body),
            Text('2. 指标正交不堆砌：1 个趋势/状态维度（EMA）+ 1 个波动率硬止损维度（Wilder ATR）远胜十几个同质化震荡指标堆出的 if/else。')->font(Font::body),
            Text('3. 以损定量（Position Sizing）：每笔交易开仓量由「净值 × 1% 风险预算 ÷ ATR 止损距离」反推，绝不无止损硬扛或百倍杠杆赌命。')->font(Font::body),
            Text('4. 只选参数高原，拒绝孤岛尖峰：在「C 原生参数高原」页确认邻近参数格 >=65% 同步盈利，严禁挑单点最优值实盘。')->font(Font::body),
            Text('5. 百万级回测零拷贝架构：数据常驻 C 端 Columnar SoA 列内存 + 二进制 LOD 极值金字塔，PHP 仅持句柄与视图切片，兼顾微秒级缩放与多核并行寻优。')->font(Font::body),
        ),
    )->padding(Edge::horizontal, 20)->padding(Edge::bottom, 20)->padding(Edge::top, 8)
)->navigationTitle('量化手册');

return fn() => TabView(selection: $this->tab)(
    Tab('回测实验室', systemImage: 'chart.xyaxis.line', value: 'lab')($labPage()),
    Tab('C 原生参数高原', systemImage: 'cpu', value: 'sweep')($sweepPage()),
    Tab('成交账本与摩擦', systemImage: 'list.bullet.rectangle', value: 'journal')($journalPage()),
    Tab('量化手册', systemImage: 'book.closed', value: 'guide')($guidePage()),
)->tabViewStyle(TabViewStyle::sidebarAdaptable)->tint($accent);
