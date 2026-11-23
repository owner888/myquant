<?php
// Declarative GPU UI for MyQuant ($this is MyQuant\QuantState). Hot-reloads on save.
declare(strict_types=1);

use MyQuant\Data\MarketFeed;

$accent = Color::rgb(0x10B981);

$card = fn(View ...$children) => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(...$children)
    ->padding(14)
    ->frame(maxWidth: INF, alignment: Alignment::topLeading)
    ->background(Color::secondarySystemGroupedBackground)
    ->cornerRadius(12);

$metricTile = fn(string $label, string $value, string $sub, mixed $tone = null) => VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
    Text($label)->font(Font::caption)->foregroundStyle(Color::secondary),
    Text(verbatim: $value)->font(Font::system(size: 20, weight: Weight::bold))->foregroundStyle($tone ?? Color::primary),
    Text(verbatim: $sub)->font(Font::caption2)->foregroundStyle(Color::secondary),
)->padding(12)->frame(maxWidth: INF, alignment: Alignment::topLeading)->background(Color::tertiarySystemGroupedBackground)->cornerRadius(10);

$marketHeader = fn() => $card(
    HStack(alignment: VerticalAlignment::center, spacing: 10)(
        Button('黄金外汇 · Exness Zero', action: fn() => $this->switchMarket('forex'))
            ->buttonStyle($this->market === 'forex' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
        Button('加密市场 · Binance', action: fn() => $this->switchMarket('crypto'))
            ->buttonStyle($this->market === 'crypto' ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
        Spacer(),
        Button($this->syncing ? '同步中…' : '同步实盘行情', systemImage: 'arrow.clockwise', action: fn() => $this->loadDataset(true))
            ->buttonStyle(ButtonStyle::bordered)
            ->disabled($this->syncing),
    ),
    HStack(alignment: VerticalAlignment::center, spacing: 8)(
        Text('品种:')->font(Font::caption)->foregroundStyle(Color::secondary),
        array_map(
            fn(string $sym) => Button($sym, action: fn() => $this->selectSymbol($sym))
                ->buttonStyle($this->symbol === $sym ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
            $this->currentSymbols()
        ),
        Spacer(),
        Text('周期:')->font(Font::caption)->foregroundStyle(Color::secondary),
        array_map(
            fn(string $iv) => Button($iv, action: fn() => $this->selectInterval($iv))
                ->buttonStyle($this->interval === $iv ? ButtonStyle::borderedProminent : ButtonStyle::bordered),
            MarketFeed::INTERVALS
        ),
    ),
    Text(verbatim: $this->statusText)->font(Font::caption)->foregroundStyle(Color::secondary),
);

$labPage = function () use ($card, $metricTile, $marketHeader, $accent): View {
    $r = $this->report;
    $ret = (float)($r['netReturnPct'] ?? 0.0);
    $bh = (float)($r['buyHoldReturnPct'] ?? 0.0);
    $pf = (float)($r['profitFactor'] ?? 0.0);
    $dd = (float)($r['maxDrawdownPct'] ?? 0.0);
    $calmar = (float)($r['calmarRatio'] ?? 0.0);
    $expR = (float)($r['expectancyR'] ?? 0.0);
    $wr = (float)($r['winRatePct'] ?? 0.0);
    $trades = (int)($r['closedTrades'] ?? 0);
    $fees = (float)($r['totalFeesQuote'] ?? 0.0);

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $marketHeader(),
            LazyVGrid(columns: [GridItem::adaptive(minimum: 220)], spacing: 10)(
                $metricTile(
                    '策略净收益 (扣摩擦)',
                    sprintf('%+.2f%%', $ret),
                    sprintf('买死不动 B&H: %+.2f%% · 终值 $%.0f', $bh, (float)($r['finalEquity'] ?? 10000.0)),
                    $ret >= 0 ? Color::rgb(0x10B981) : Color::rgb(0xEF4444),
                ),
                $metricTile(
                    'Profit Factor (获利因子)',
                    sprintf('%.2f', $pf),
                    $pf >= 1.5 ? '优秀 (>1.5 正期望稳健)' : ($pf >= 1.05 ? '微利边缘 (注意滑点损耗)' : '负期望 (<1.0 禁止实盘)'),
                    $pf >= 1.5 ? Color::rgb(0x10B981) : ($pf >= 1.05 ? Color::rgb(0xF59E0B) : Color::rgb(0xEF4444)),
                ),
                $metricTile(
                    '最大回撤 / Calmar',
                    sprintf('-%.2f%%', $dd),
                    sprintf('收益回撤比 Calmar: %.2f', $calmar),
                    $dd <= 15.0 ? Color::rgb(0x10B981) : Color::rgb(0xF59E0B),
                ),
                $metricTile(
                    '单笔期望 (R) / 交易样本',
                    sprintf('%+.2f R', $expR),
                    sprintf('胜率 %.1f%% · %d 笔 · 累计摩擦 $%.1f', $wr, $trades, $fees),
                    $expR > 0 ? Color::rgb(0x10B981) : Color::rgb(0xEF4444),
                ),
            ),
            $card(
                HStack()(
                    Text('策略净值曲线（绿） vs 买死不动基准（灰）')->font(Font::headline),
                    Spacer(),
                    Text(verbatim: sprintf('Fast %.0f / Slow %.0f / ATR %.1fx · 单笔风险 %.1f%% · 单边摩擦 %.1f bps', $this->fastEma, $this->slowEma, $this->atrMult, $this->riskPct, $this->feeBps))
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
                    ->frame(height: 210),
                Text('水下回撤曲线 (Underwater Drawdown %)')->font(Font::subheadline)->foregroundStyle(Color::secondary),
                Chart()(
                    LinePlot(x: $r['xs'] ?? [0.0, 1.0], y: $r['drawdownYs'] ?? [0.0, 0.0])
                        ->foregroundStyle(Color::rgb(0xEF4444)),
                )
                    ->chartYScale(domain: [(float)($r['ddMin'] ?? -10.0), 0.5])
                    ->chartYAxis(new AxisMarks(desiredCount: 4, format: fn(float $v) => sprintf('%.1f%%', $v)))
                    ->frame(height: 100),
            ),
            $card(
                Text('正交策略参数与「以损定量」风控调节')->font(Font::headline),
                HStack(spacing: 16)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('快线 EMA 周期: %.0f', $this->fastEma))->font(Font::caption),
                        Slider(value: $this->fastEma, in: [4.0, 35.0], step: 1.0, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    )->frame(maxWidth: INF),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('慢线 EMA 周期: %.0f', $this->slowEma))->font(Font::caption),
                        Slider(value: $this->slowEma, in: [15.0, 120.0], step: 1.0, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    )->frame(maxWidth: INF),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('ATR 硬止损倍数: %.1f x', $this->atrMult))->font(Font::caption),
                        Slider(value: $this->atrMult, in: [1.0, 4.0], step: 0.25, onEditingChanged: fn(bool $e) => !$e && $this->runBacktest()),
                    )->frame(maxWidth: INF),
                ),
                HStack(spacing: 16)(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('单笔最大允许亏损 (以损定量): %.1f%%', $this->riskPct))->font(Font::caption),
                        Slider(value: $this->riskPct, in: [0.25, 3.0], step: 0.25, onEditingChanged: fn(bool $e) => !$e && $this->recomputeAll()),
                    )->frame(maxWidth: INF),
                    VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        Text(verbatim: sprintf('单边交易摩擦 (手续费+滑点): %.1f bps (%.3f%%)', $this->feeBps, $this->feeBps * 0.01))->font(Font::caption),
                        Slider(value: $this->feeBps, in: [0.0, 15.0], step: 0.2, onEditingChanged: fn(bool $e) => !$e && $this->recomputeAll()),
                    )->frame(maxWidth: INF),
                ),
                Divider(),
                HStack(spacing: 8)(
                    TextField('导入本地 Exness Tick ZIP 或 MT5 CSV 路径…', text: $this->importPath)
                        ->onSubmit(fn() => $this->importLocalFile()),
                    Button('导入数据', systemImage: 'tray.and.arrow.down', action: fn() => $this->importLocalFile())
                        ->buttonStyle(ButtonStyle::bordered),
                ),
            ),
        )->padding(16)
    );
};

$sweepPage = function () use ($card, $metricTile, $marketHeader): View {
    $sw = $this->sweep;
    $usedC = (bool)($sw['usedNativeC'] ?? false);
    $rows = $sw['rows'] ?? [];

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $marketHeader(),
            LazyVGrid(columns: [GridItem::adaptive(minimum: 220)], spacing: 10)(
                $metricTile(
                    'FFI 计算引擎',
                    $usedC ? 'Native C (FFI)' : 'PHP Fallback',
                    sprintf('%s · 耗时 %.2f ms', (string)($sw['libraryPath'] ?? ''), (float)($sw['elapsedMs'] ?? 0.0)),
                    $usedC ? Color::rgb(0x10B981) : Color::rgb(0xF59E0B),
                ),
                $metricTile(
                    '参数网格总组合数',
                    sprintf('%d 组', (int)($sw['totalCombos'] ?? 0)),
                    sprintf('正收益组合: %d 组', (int)($sw['profitableCombos'] ?? 0)),
                ),
                $metricTile(
                    '高原稳健区 (防过拟合)',
                    sprintf('%d 组', (int)($sw['plateauCombos'] ?? 0)),
                    '周围 >=65% 邻格均保持正期望',
                    (int)($sw['plateauCombos'] ?? 0) > 0 ? Color::rgb(0x10B981) : Color::rgb(0xEF4444),
                ),
            ),
            $card(
                HStack()(
                    VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
                        Text('三维参数高原排行榜 (Fast EMA × Slow EMA × ATR 倍数)')->font(Font::headline),
                        Text('排序优先选择「周围邻居全部稳定赚钱」的参数高原，自动降权「孤岛尖峰过拟合」参数。')->font(Font::caption)->foregroundStyle(Color::secondary),
                    ),
                    Spacer(),
                    Button('重新扫描网格', systemImage: 'bolt.fill', action: fn() => $this->runSweep())
                        ->buttonStyle(ButtonStyle::borderedProminent),
                ),
                Divider(),
                ...array_map(function (array $row): View {
                    $badgeColor = match ($row['regime']) {
                        '高原稳健' => Color::rgb(0x10B981),
                        '孤岛过拟合' => Color::rgb(0xEF4444),
                        '边缘过渡' => Color::rgb(0xF59E0B),
                        default => Color::secondary,
                    };
                    return HStack(alignment: VerticalAlignment::center, spacing: 10)(
                        Text(verbatim: sprintf('EMA(%d, %d) · ATR %.1fx', $row['fast'], $row['slow'], $row['atrMult']))
                            ->font(Font::system(size: 13, weight: Weight::semibold))
                            ->frame(width: 175, alignment: Alignment::leading),
                        Text(verbatim: $row['regime'])
                            ->font(Font::caption)
                            ->foregroundStyle($badgeColor)
                            ->frame(width: 85, alignment: Alignment::leading),
                        Text(verbatim: sprintf('邻格通过 %.0f%%', $row['neighborPassPct']))
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary)
                            ->frame(width: 95, alignment: Alignment::leading),
                        Text(verbatim: sprintf('收益 %+.1f%%', $row['netReturnPct']))
                            ->font(Font::caption)
                            ->foregroundStyle($row['netReturnPct'] >= 0 ? Color::rgb(0x10B981) : Color::rgb(0xEF4444))
                            ->frame(width: 95, alignment: Alignment::leading),
                        Text(verbatim: sprintf('PF %.2f · 回撤 -%.1f%% · %d笔', $row['profitFactor'], $row['maxDrawdownPct'], $row['trades']))
                            ->font(Font::caption)
                            ->foregroundStyle(Color::secondary),
                        Spacer(),
                        Button('套用', action: fn() => $this->applySweepRow($row['fast'], $row['slow'], $row['atrMult']))
                            ->buttonStyle(ButtonStyle::bordered),
                    )->padding(Edge::vertical, 4);
                }, array_slice($rows, 0, 18)),
            ),
        )->padding(16)
    );
};

$journalPage = function () use ($card): View {
    $r = $this->report;
    $trades = $r['trades'] ?? [];
    $grossWin = (float)($r['grossProfitQuote'] ?? 0.0);
    $fees = (float)($r['totalFeesQuote'] ?? 0.0);
    $feeShare = $grossWin > 1.0 ? ($fees / $grossWin * 100.0) : 0.0;

    return ScrollView(Axis::vertical)(
        VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
            $card(
                Text('交易摩擦与归因体检')->font(Font::headline),
                Text(verbatim: sprintf(
                    '总毛利: $%.2f  |  总毛亏: -$%.2f  |  累计摩擦支出(手续费+滑点): $%.2f (占毛利 %.1f%%)',
                    $grossWin,
                    (float)($r['grossLossQuote'] ?? 0.0),
                    $fees,
                    $feeShare
                ))->font(Font::subheadline)->foregroundStyle($feeShare > 35.0 ? Color::rgb(0xEF4444) : Color::rgb(0x10B981)),
                Text($feeShare > 35.0
                    ? '⚠️ 警告：当前周期下手续费吞噬了超过 35% 的毛利！请放大 K 线周期（如 4h/1d）或切换到低摩擦市场（如 Exness Zero 黄金）。'
                    : '摩擦占比健康：当前周期波动幅度足以覆盖进出交易成本。'
                )->font(Font::caption)->foregroundStyle(Color::secondary),
            ),
            $card(
                Text(verbatim: sprintf('历史闭合交易明细（最新 %d / 共 %d 笔）', min(30, count($trades)), count($trades)))->font(Font::headline),
                $trades === []
                    ? ContentUnavailableView('暂无闭合交易', systemImage: 'tray', description: '请调整参数或切换周期运行回测。')
                    : VStack(alignment: HorizontalAlignment::leading, spacing: 6)(
                        ...array_map(fn(array $t) => HStack(spacing: 10)(
                            Text(verbatim: sprintf('#%d', $t['id']))->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 36, alignment: Alignment::leading),
                            Text(verbatim: "{$t['entryTime']} → {$t['exitTime']}")->font(Font::caption)->frame(width: 145, alignment: Alignment::leading),
                            Text(verbatim: sprintf('入 %.2f / 出 %.2f', $t['entryPrice'], $t['exitPrice']))->font(Font::caption)->frame(width: 175, alignment: Alignment::leading),
                            Text(verbatim: $t['reason'])->font(Font::caption)->foregroundStyle(Color::secondary)->frame(width: 95, alignment: Alignment::leading),
                            Spacer(),
                            Text(verbatim: sprintf('%+.2f R  (%+.2f$)', $t['rMultiple'], $t['pnl']))
                                ->font(Font::system(size: 13, weight: Weight::semibold))
                                ->foregroundStyle($t['pnl'] >= 0 ? Color::rgb(0x10B981) : Color::rgb(0xEF4444)),
                        )->padding(Edge::vertical, 3), array_slice($trades, 0, 30))
                    ),
            ),
        )->padding(16)
    );
};

$guidePage = fn() => ScrollView(Axis::vertical)(
    VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
        $card(
            Text('量化交易五条铁律（实战检验清单）')->font(Font::title3),
            Text('1. 先算摩擦账再选周期：币圈合约单边 8 bps 适合 4h/1d 趋势；零点差黄金 XAUUSDz 单边 0.2 bps 才具备 5m/15m 日内交易的物理基础。')->font(Font::body),
            Text('2. 指标正交不堆砌：1 个趋势/状态维度（EMA）+ 1 个波动率硬止损维度（Wilder ATR）远胜十几个同质化震荡指标堆出的 if/else。')->font(Font::body),
            Text('3. 以损定量（Position Sizing）：每笔交易开仓量由「净值 × 1% 风险预算 ÷ ATR 止损距离」反推，绝不无止损硬扛或百倍杠杆赌命。')->font(Font::body),
            Text('4. 只选参数高原，拒绝孤岛尖峰：在「C 原生参数高原」页确认邻近参数格 >=65% 同步盈利，严禁挑单点最优值实盘。')->font(Font::body),
            Text('5. 样本外与模拟盘门禁：任何策略必须覆盖 >=100 笔交易样本，通过未参与调参的样本外区间验证后再上小资金。')->font(Font::body),
        ),
    )->padding(16)
);

return fn() => TabView(selection: $this->tab)(
    Tab('回测实验室', systemImage: 'chart.xyaxis.line', value: 'lab')($labPage()),
    Tab('C 原生参数高原', systemImage: 'cpu', value: 'sweep')($sweepPage()),
    Tab('成交账本与摩擦', systemImage: 'list.bullet.rectangle', value: 'journal')($journalPage()),
    Tab('量化手册', systemImage: 'book.closed', value: 'guide')($guidePage()),
)->tabViewStyle(TabViewStyle::sidebarAdaptable)->tint($accent);
